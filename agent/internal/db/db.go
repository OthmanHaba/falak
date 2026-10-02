// Package db implements db.* commands for MySQL/MariaDB and PostgreSQL through their CLIs.
// SQL is always passed on stdin (never argv) so passwords never show up in the process table or logs.
package db

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"os"
	"regexp"
	"slices"
	"sort"
	"strings"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/hostfs"
	"github.com/kiln/agent/internal/runner"
)

// Deps are the collaborators of db executors.
type Deps struct {
	Runner   runner.Runner
	FS       hostfs.FS
	Logger   *slog.Logger
	HTTP     *http.Client
	StateDir string // host path; default /var/lib/kiln
	TempDir  string // real path for backup staging; default os.TempDir()
}

// DB holds the executors.
type DB struct{ d Deps }

// New builds db executors.
func New(d Deps) *DB {
	if d.Logger == nil {
		d.Logger = slog.Default()
	}
	if d.HTTP == nil {
		d.HTTP = http.DefaultClient
	}
	if d.StateDir == "" {
		d.StateDir = "/var/lib/kiln"
	}
	if d.TempDir == "" {
		d.TempDir = os.TempDir()
	}
	return &DB{d: d}
}

// Register adds db.* executors.
func (db *DB) Register(reg *commands.Registry) {
	reg.Register("db.create", commands.Typed(db.Create))
	reg.Register("db.drop", commands.Typed(db.Drop))
	reg.Register("db.user.apply", commands.Typed(db.UserApply))
	reg.Register("db.backup", commands.Typed(db.Backup))
	reg.Register("db.restore", commands.Typed(db.Restore))
}

var ident = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]{0,62}$`)
var privRe = regexp.MustCompile(`^[A-Z ]+$`)

func checkIdent(kind, s string) error {
	if !ident.MatchString(s) {
		return &commands.PayloadError{Err: fmt.Errorf("invalid %s %q", kind, s)}
	}
	return nil
}

// Quoting helpers.
func myIdent(s string) string { return "`" + strings.ReplaceAll(s, "`", "``") + "`" }
func myLit(s string) string {
	return "'" + strings.NewReplacer(`\`, `\\`, `'`, `''`, "\x00", `\0`).Replace(s) + "'"
}
func pgIdent(s string) string { return `"` + strings.ReplaceAll(s, `"`, `""`) + `"` }
func pgLit(s string) string   { return "'" + strings.ReplaceAll(s, "'", "''") + "'" }

// engine abstracts the two CLIs.
type engine struct {
	name string
	r    runner.Runner
}

func (db *DB) engine(name string) (engine, error) {
	switch name {
	case "mysql", "postgres":
		return engine{name, db.d.Runner}, nil
	}
	return engine{}, &commands.PayloadError{Err: fmt.Errorf("unknown engine %q", name)}
}

// cmd builds the client invocation against database dbname ("" = server default).
func (e engine) cmd(dbname string) runner.Cmd {
	if e.name == "mysql" {
		args := []string{"--batch", "--skip-column-names", "--default-character-set=utf8mb4"}
		if dbname != "" {
			args = append(args, dbname)
		}
		return runner.Cmd{Name: "mysql", Args: args}
	}
	if dbname == "" {
		dbname = "postgres"
	}
	return runner.Cmd{Name: "psql", Args: []string{"-X", "-q", "-t", "-A", "-v", "ON_ERROR_STOP=1", "-d", dbname}, User: "postgres"}
}

// exec runs SQL (on stdin) and returns trimmed stdout. Errors never include the SQL.
func (e engine) exec(ctx context.Context, dbname, sql string) (string, error) {
	c := e.cmd(dbname)
	c.Stdin = strings.NewReader(sql)
	res, err := runner.Check(ctx, e.r, c)
	if err != nil {
		return "", err
	}
	return strings.TrimSpace(string(res.Stdout)), nil
}

func (e engine) dbExists(ctx context.Context, name string) (bool, error) {
	q := "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=" + myLit(name) + ";"
	if e.name == "postgres" {
		q = "SELECT datname FROM pg_database WHERE datname=" + pgLit(name) + ";"
	}
	out, err := e.exec(ctx, "", q)
	return out == name, err
}

// ---- db.create / db.drop ----

// CreatePayload is db.create.
type CreatePayload struct {
	Engine    string `json:"engine"`
	Name      string `json:"name"`
	Charset   string `json:"charset"`
	Collation string `json:"collation"`
	Owner     string `json:"owner"`
}

// ChangedResult is {changed}.
type ChangedResult struct {
	Changed bool `json:"changed"`
}

// Create creates a database if missing (postgres: also converges the owner). For MySQL `owner`
// is ignored (ownership is expressed with db.user.apply grants).
func (db *DB) Create(ctx context.Context, p CreatePayload, _ commands.Stream) (any, error) {
	e, err := db.engine(p.Engine)
	if err != nil {
		return nil, err
	}
	if err := checkIdent("name", p.Name); err != nil {
		return nil, err
	}
	if p.Owner != "" {
		if err := checkIdent("owner", p.Owner); err != nil {
			return nil, err
		}
	}
	ok, err := e.dbExists(ctx, p.Name)
	if err != nil {
		return nil, err
	}
	if e.name == "mysql" {
		if ok {
			return ChangedResult{}, nil
		}
		cs := p.Charset
		if cs == "" {
			cs = "utf8mb4"
		}
		if !ident.MatchString(cs) || (p.Collation != "" && !ident.MatchString(p.Collation)) {
			return nil, &commands.PayloadError{Err: errors.New("invalid charset/collation")}
		}
		q := "CREATE DATABASE IF NOT EXISTS " + myIdent(p.Name) + " CHARACTER SET " + cs
		if p.Collation != "" {
			q += " COLLATE " + p.Collation
		}
		_, err := e.exec(ctx, "", q+";")
		return ChangedResult{Changed: err == nil}, err
	}
	if !ok {
		q := "CREATE DATABASE " + pgIdent(p.Name) + " ENCODING 'UTF8' TEMPLATE template0"
		if p.Owner != "" {
			q += " OWNER " + pgIdent(p.Owner)
		}
		_, err := e.exec(ctx, "", q+";")
		return ChangedResult{Changed: err == nil}, err
	}
	if p.Owner != "" {
		cur, err := e.exec(ctx, "", "SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname="+pgLit(p.Name)+";")
		if err != nil {
			return nil, err
		}
		if cur != p.Owner {
			_, err := e.exec(ctx, "", "ALTER DATABASE "+pgIdent(p.Name)+" OWNER TO "+pgIdent(p.Owner)+";")
			return ChangedResult{Changed: err == nil}, err
		}
	}
	return ChangedResult{}, nil
}

// DropPayload is db.drop.
type DropPayload struct {
	Engine string `json:"engine"`
	Name   string `json:"name"`
}

// Drop drops a database if present.
func (db *DB) Drop(ctx context.Context, p DropPayload, _ commands.Stream) (any, error) {
	e, err := db.engine(p.Engine)
	if err != nil {
		return nil, err
	}
	if err := checkIdent("name", p.Name); err != nil {
		return nil, err
	}
	ok, err := e.dbExists(ctx, p.Name)
	if err != nil || !ok {
		return ChangedResult{}, err
	}
	q := "DROP DATABASE IF EXISTS " + myIdent(p.Name) + ";"
	if e.name == "postgres" {
		q = "DROP DATABASE IF EXISTS " + pgIdent(p.Name) + " WITH (FORCE);"
	}
	_, err = e.exec(ctx, "", q)
	return ChangedResult{Changed: err == nil}, err
}

// ---- db.user.apply ----

// Grant is one database grant.
type Grant struct {
	Database   string   `json:"database"`
	Privileges []string `json:"privileges"`
}

// UserPayload is db.user.apply.
type UserPayload struct {
	Engine   string  `json:"engine"`
	Username string  `json:"username"`
	Password string  `json:"password"`
	Host     string  `json:"host"`
	Grants   []Grant `json:"grants"`
	State    string  `json:"state"`
	// Remote: the user connects from other servers (dedicated database server). The engine is made to
	// listen on every interface and, for PostgreSQL, a scram-sha-256 host rule is kept for the user.
	Remote bool `json:"remote"`
	// Containers are the Docker address ranges (IPv4 CIDRs) the user also connects from: containers on this server
	// (compose stacks, Docker sites, functions) reach the engine on the host address. The engine listens on every
	// interface; the firewall only lets the Docker bridges in (net.firewall.apply container_ports). PostgreSQL gets a
	// host rule per range, MySQL an extra account per range (user@'172.16.0.0/255.240.0.0').
	Containers []string `json:"containers,omitempty"`
}

// userState is what we last applied (passwords only as a keyed fingerprint), stored 0600 so that
// re-applying the same desired state is a no-op (server-side password hashes are salted).
type userState struct {
	PasswordFP string              `json:"password_fp"`
	Grants     map[string][]string `json:"grants"`
	Remote     bool                `json:"remote,omitempty"`
	Containers []string            `json:"containers,omitempty"`
	// ContainerOf: this MySQL account exists for the container ranges of the account named here.
	ContainerOf string `json:"container_of,omitempty"`
}

func (db *DB) statePath() string { return strings.TrimRight(db.d.StateDir, "/") + "/db/users.json" }

func (db *DB) loadState() map[string]userState {
	m := map[string]userState{}
	if b, err := db.d.FS.ReadFile(db.statePath()); err == nil {
		_ = json.Unmarshal(b, &m)
	}
	return m
}

func (db *DB) saveState(m map[string]userState) error {
	b, _ := json.MarshalIndent(m, "", "  ")
	if err := db.d.FS.MkdirAll(strings.TrimRight(db.d.StateDir, "/")+"/db", 0o700); err != nil {
		return err
	}
	_, err := db.d.FS.WriteFile(db.statePath(), b, 0o600)
	return err
}

func fingerprint(key, password string) string {
	s := sha256.Sum256([]byte("kiln-db-user\x00" + key + "\x00" + password))
	return hex.EncodeToString(s[:])
}

func normPrivs(p []string) []string {
	if len(p) == 0 {
		return []string{"ALL PRIVILEGES"}
	}
	out := make([]string, len(p))
	for i, x := range p {
		x = strings.TrimSpace(x)
		if x == "ALL" {
			x = "ALL PRIVILEGES"
		}
		out[i] = x
	}
	sort.Strings(out)
	return out
}

// UserApply converges a user and its grants (full desired set: grants no longer listed are revoked).
func (db *DB) UserApply(ctx context.Context, p UserPayload, _ commands.Stream) (any, error) {
	e, err := db.engine(p.Engine)
	if err != nil {
		return nil, err
	}
	hosts, err := containerHosts(p.Containers)
	if err != nil {
		return nil, err
	}
	changed, err := db.userApply(ctx, e, p, "")
	if err != nil {
		return nil, err
	}
	if e.name == "mysql" {
		if p.State == "absent" {
			hosts = nil
		}
		c, err := db.syncContainerAccounts(ctx, e, p, hosts)
		if err != nil {
			return nil, err
		}
		changed = changed || c
	}
	return ChangedResult{Changed: changed}, nil
}

// containerHosts validates the container ranges and returns them as MySQL account hosts (ip/netmask).
func containerHosts(cidrs []string) ([]string, error) {
	var hosts []string
	for _, c := range cidrs {
		_, n, err := net.ParseCIDR(c)
		if err != nil || n.IP.To4() == nil || n.String() != c {
			return nil, &commands.PayloadError{Err: fmt.Errorf("containers: %q is not an IPv4 network in CIDR form", c)}
		}
		hosts = append(hosts, n.IP.String()+"/"+net.IP(n.Mask).String())
	}
	return hosts, nil
}

// syncContainerAccounts keeps one MySQL account per container range (user@'172.16.0.0/255.240.0.0', same password
// and grants as the main account) and drops the ones no longer wanted.
func (db *DB) syncContainerAccounts(ctx context.Context, e engine, p UserPayload, hosts []string) (bool, error) {
	main := p.Host
	if main == "" {
		main = "%"
	}
	owner := e.name + "/" + p.Username + "@" + main
	changed := false
	for _, h := range hosts {
		c, err := db.userApply(ctx, e, UserPayload{Engine: p.Engine, Username: p.Username, Password: p.Password, Host: h, Grants: p.Grants, State: "present"}, owner)
		if err != nil {
			return false, err
		}
		changed = changed || c
	}
	for key, s := range db.loadState() {
		if s.ContainerOf != owner {
			continue
		}
		_, host, _ := strings.Cut(strings.TrimPrefix(key, e.name+"/"), "@")
		if slices.Contains(hosts, host) {
			continue
		}
		if _, err := db.userApply(ctx, e, UserPayload{Engine: p.Engine, Username: p.Username, Host: host, State: "absent"}, owner); err != nil {
			return false, err
		}
		changed = true
	}
	return changed, nil
}

// userApply converges one account; containerOf marks a MySQL container account of that owner key.
func (db *DB) userApply(ctx context.Context, e engine, p UserPayload, containerOf string) (bool, error) {
	if err := checkIdent("username", p.Username); err != nil {
		return false, err
	}
	if e.name == "mysql" {
		// MySQL container ranges are separate accounts (syncContainerAccounts), not host rules.
		p.Containers = nil
	}
	host := p.Host
	if host == "" {
		host = "%"
	}
	key := e.name + "/" + p.Username + "@" + host
	desired := map[string][]string{}
	for _, g := range p.Grants {
		if err := checkIdent("database", g.Database); err != nil {
			return false, err
		}
		privs := normPrivs(g.Privileges)
		for _, pr := range privs {
			if !privRe.MatchString(pr) {
				return false, &commands.PayloadError{Err: fmt.Errorf("invalid privilege %q", pr)}
			}
		}
		desired[g.Database] = privs
	}
	states := db.loadState()
	prev := states[key]
	exposedBefore := prev.Remote || len(prev.Containers) > 0 || prev.ContainerOf != ""

	var existsQ, userSQL string
	if e.name == "mysql" {
		existsQ = "SELECT COUNT(*) FROM mysql.user WHERE User=" + myLit(p.Username) + " AND Host=" + myLit(host) + ";"
	} else {
		existsQ = "SELECT COUNT(*) FROM pg_roles WHERE rolname=" + pgLit(p.Username) + ";"
	}
	out, err := e.exec(ctx, "", existsQ)
	if err != nil {
		return false, err
	}
	exists := out != "" && out != "0"

	if p.State == "absent" {
		delete(states, key)
		if exposedBefore {
			if err := db.syncRemoteAccess(ctx, e, states); err != nil {
				return false, err
			}
		}
		if !exists {
			return exposedBefore, db.saveState(states)
		}
		q := "DROP USER IF EXISTS " + myLit(p.Username) + "@" + myLit(host) + ";"
		if e.name == "postgres" {
			q = "DROP ROLE IF EXISTS " + pgIdent(p.Username) + ";"
		}
		if _, err := e.exec(ctx, "", q); err != nil {
			return false, err
		}
		return true, db.saveState(states)
	}
	if p.Password == "" {
		return false, &commands.PayloadError{Err: errors.New("password is required when state=present")}
	}
	fp := fingerprint(key, p.Password)
	changed := false
	switch {
	case !exists && e.name == "mysql":
		userSQL = "CREATE USER " + myLit(p.Username) + "@" + myLit(host) + " IDENTIFIED BY " + myLit(p.Password) + ";"
	case exists && e.name == "mysql" && prev.PasswordFP != fp:
		userSQL = "ALTER USER " + myLit(p.Username) + "@" + myLit(host) + " IDENTIFIED BY " + myLit(p.Password) + ";"
	case !exists:
		userSQL = "CREATE ROLE " + pgIdent(p.Username) + " WITH LOGIN PASSWORD " + pgLit(p.Password) + ";"
	case prev.PasswordFP != fp:
		userSQL = "ALTER ROLE " + pgIdent(p.Username) + " WITH LOGIN PASSWORD " + pgLit(p.Password) + ";"
	}
	if userSQL != "" {
		if _, err := e.exec(ctx, "", userSQL); err != nil {
			return false, fmt.Errorf("apply user %s: %w", p.Username, err)
		}
		changed = true
	}
	grantsChanged := !exists || !sameGrants(prev.Grants, desired)
	if grantsChanged {
		for dbname := range prev.Grants {
			if _, keep := desired[dbname]; !keep {
				if err := db.revoke(ctx, e, p.Username, host, dbname); err != nil {
					return false, err
				}
			}
		}
		for _, dbname := range sortedKeys(desired) {
			if err := db.grant(ctx, e, p.Username, host, dbname, desired[dbname]); err != nil {
				return false, err
			}
		}
		changed = true
	}
	next := userState{PasswordFP: fp, Grants: desired, Remote: p.Remote, Containers: p.Containers, ContainerOf: containerOf}
	states[key] = next
	if exposedBefore || next.Remote || len(next.Containers) > 0 || next.ContainerOf != "" {
		if err := db.syncRemoteAccess(ctx, e, states); err != nil {
			return false, err
		}
		changed = changed || p.Remote != prev.Remote || !slices.Equal(p.Containers, prev.Containers) || containerOf != prev.ContainerOf
	}
	return changed, db.saveState(states)
}

func sameGrants(a, b map[string][]string) bool {
	if len(a) != len(b) {
		return false
	}
	for k, v := range a {
		if strings.Join(v, ",") != strings.Join(b[k], ",") {
			return false
		}
	}
	return true
}

func sortedKeys[V any](m map[string]V) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	sort.Strings(out)
	return out
}

func isAll(privs []string) bool { return len(privs) == 1 && privs[0] == "ALL PRIVILEGES" }

func (db *DB) grant(ctx context.Context, e engine, user, host, dbname string, privs []string) error {
	if e.name == "mysql" {
		who := myLit(user) + "@" + myLit(host)
		// Revoke first so a narrowed privilege list actually narrows.
		_, err := e.exec(ctx, "", "REVOKE ALL PRIVILEGES ON "+myIdent(dbname)+".* FROM "+who+";\n"+
			"GRANT "+strings.Join(privs, ", ")+" ON "+myIdent(dbname)+".* TO "+who+";")
		if err != nil && strings.Contains(err.Error(), "1141") { // no such grant yet
			_, err = e.exec(ctx, "", "GRANT "+strings.Join(privs, ", ")+" ON "+myIdent(dbname)+".* TO "+who+";")
		}
		return err
	}
	u := pgIdent(user)
	if isAll(privs) {
		if _, err := e.exec(ctx, "", "GRANT ALL PRIVILEGES ON DATABASE "+pgIdent(dbname)+" TO "+u+";"); err != nil {
			return err
		}
		_, err := e.exec(ctx, dbname, "GRANT ALL ON SCHEMA public TO "+u+";\n"+
			"GRANT ALL ON ALL TABLES IN SCHEMA public TO "+u+";\n"+
			"GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO "+u+";\n"+
			"ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO "+u+";\n"+
			"ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO "+u+";")
		return err
	}
	pl := strings.Join(privs, ", ")
	if _, err := e.exec(ctx, "", "GRANT CONNECT ON DATABASE "+pgIdent(dbname)+" TO "+u+";"); err != nil {
		return err
	}
	_, err := e.exec(ctx, dbname, "GRANT USAGE ON SCHEMA public TO "+u+";\n"+
		"GRANT "+pl+" ON ALL TABLES IN SCHEMA public TO "+u+";\n"+
		"ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT "+pl+" ON TABLES TO "+u+";")
	return err
}

func (db *DB) revoke(ctx context.Context, e engine, user, host, dbname string) error {
	if e.name == "mysql" {
		_, err := e.exec(ctx, "", "REVOKE ALL PRIVILEGES ON "+myIdent(dbname)+".* FROM "+myLit(user)+"@"+myLit(host)+";")
		if err != nil && strings.Contains(err.Error(), "1141") {
			return nil
		}
		return err
	}
	ok, err := e.dbExists(ctx, dbname)
	if err != nil || !ok {
		return err
	}
	_, err = e.exec(ctx, "", "REVOKE ALL PRIVILEGES ON DATABASE "+pgIdent(dbname)+" FROM "+pgIdent(user)+";")
	return err
}
