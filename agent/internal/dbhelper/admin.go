package dbhelper

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
)

// Databases, users and the superuser password: what the agent used to do with the host's CLIs, now inside the
// container. SQL travels on the client's stdin (never argv: the process table of the container shows argv), passwords
// only come from files the agent puts in the secrets directory.

// sqlName is a database or user name: the agent's own rule (db.create / db.user.apply), so quoting is belt and braces.
var sqlName = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]{0,62}$`)

// privilege is one privilege keyword list ("SELECT", "CREATE TEMPORARY TABLES").
var privilege = regexp.MustCompile(`^[A-Z ]+$`)

func myIdent(s string) string { return "`" + strings.ReplaceAll(s, "`", "``") + "`" }
func myLit(s string) string {
	return "'" + strings.NewReplacer(`\`, `\\`, `'`, `''`, "\x00", `\0`).Replace(s) + "'"
}
func pgIdent(s string) string { return `"` + strings.ReplaceAll(s, `"`, `""`) + `"` }

func checkName(kind, s string) error {
	if !sqlName.MatchString(s) {
		return usageErr("invalid %s %q", kind, s)
	}
	return nil
}

// pgExec runs SQL (on stdin) as the superuser over the socket against dbname and returns the unaligned output.
func (h *Helper) pgExec(ctx context.Context, dbname, sql string) (string, error) {
	out, err := output(ctx, h.Run, Cmd{Name: "psql", Args: []string{
		"-h", pgSocketDir, "-p", strconv.Itoa(pgPort), "-U", h.pgUser(), "-d", dbname,
		"-AtqX", "-v", "ON_ERROR_STOP=1", "-f", "-",
	}, Stdin: strings.NewReader(sql)})
	return strings.TrimSpace(out), err
}

// myExec runs SQL (on stdin) as root over the socket, the credentials on fd 3.
func (h *Helper) myExec(ctx context.Context, sql string) (string, error) {
	creds, err := h.mysqlDefaults()
	if err != nil {
		return "", err
	}
	c := myCmd(creds, h.Engine.client("mysql"), "-N", "-B", "--default-character-set=utf8mb4")
	c.Stdin = strings.NewReader(sql)
	out, err := output(ctx, h.Run, c)
	return strings.TrimSpace(out), err
}

// pgSchemaGrants grants role on every schema of the database (not only public: apps create their own): schemaPriv on
// the schema, tablePrivs on its tables (and sequences when withSequences), and the same by default on what the
// superuser creates there later (restores). privileges come from normPrivileges (checked against `^[A-Z ]+$`).
func pgSchemaGrants(role, schemaPriv, tablePrivs string, withSequences bool) string {
	seq := ""
	if withSequences {
		seq = "  EXECUTE format('GRANT ALL ON ALL SEQUENCES IN SCHEMA %I TO %I', r.nspname, o);\n" +
			"  EXECUTE format('ALTER DEFAULT PRIVILEGES IN SCHEMA %I GRANT ALL ON SEQUENCES TO %I', r.nspname, o);\n"
	}
	return "DO $falak$ DECLARE r record; o text := " + pgLit(role) + "; BEGIN\n" +
		"FOR r IN SELECT n.nspname FROM pg_namespace n WHERE " + pgUserSchemas + " LOOP\n" +
		"  EXECUTE format('GRANT " + schemaPriv + " ON SCHEMA %I TO %I', r.nspname, o);\n" +
		"  EXECUTE format('GRANT " + tablePrivs + " ON ALL TABLES IN SCHEMA %I TO %I', r.nspname, o);\n" +
		"  EXECUTE format('ALTER DEFAULT PRIVILEGES IN SCHEMA %I GRANT " + tablePrivs + " ON TABLES TO %I', r.nspname, o);\n" +
		seq + "END LOOP; END $falak$;\n"
}

// myExecWith is myExec with a given root password instead of the password file's.
func (h *Helper) myExecWith(ctx context.Context, password, sql string) (string, error) {
	esc := strings.NewReplacer(`\`, `\\`, `"`, `\"`).Replace(password)
	creds := []byte(fmt.Sprintf("[client]\nuser=root\npassword=\"%s\"\nsocket=%s\n", esc, mySocket))
	c := myCmd(creds, h.Engine.client("mysql"), "-N", "-B", "--default-character-set=utf8mb4")
	c.Stdin = strings.NewReader(sql)
	out, err := output(ctx, h.Run, c)
	return strings.TrimSpace(out), err
}

// pgLit quotes a string literal for PostgreSQL (standard_conforming_strings is on).
func pgLit(s string) string { return "'" + strings.ReplaceAll(s, "'", "''") + "'" }

// DatabaseOptions are `database create`'s flags.
type DatabaseOptions struct {
	Name, Charset, Collation, Owner string
}

// DatabaseCreate is `falak-db database create`: creates the database when missing (postgres: converges the owner).
func (h *Helper) DatabaseCreate(ctx context.Context, o DatabaseOptions) error {
	if h.Engine.kv() {
		return unsupported(h.Engine, "database create")
	}
	if err := checkName("--name", o.Name); err != nil {
		return err
	}
	if o.Owner != "" {
		if err := checkName("--owner", o.Owner); err != nil {
			return err
		}
	}
	changed := false
	if h.Engine.mysqlFamily() {
		cs := o.Charset
		if cs == "" {
			cs = "utf8mb4"
		}
		if !sqlName.MatchString(cs) || (o.Collation != "" && !sqlName.MatchString(o.Collation)) {
			return usageErr("invalid --charset or --collation")
		}
		exists, err := h.myExec(ctx, "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME="+myLit(o.Name)+";")
		if err != nil {
			return err
		}
		if exists == "0" {
			q := "CREATE DATABASE IF NOT EXISTS " + myIdent(o.Name) + " CHARACTER SET " + cs
			if o.Collation != "" {
				q += " COLLATE " + o.Collation
			}
			if _, err := h.myExec(ctx, q+";"); err != nil {
				return err
			}
			changed = true
		}
		return h.result(map[string]any{"changed": changed})
	}
	cur, err := h.pgExec(ctx, "postgres", "SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname="+pgLit(o.Name)+";")
	if err != nil {
		return err
	}
	switch {
	case cur == "":
		q := "CREATE DATABASE " + pgIdent(o.Name) + " ENCODING 'UTF8' TEMPLATE template0"
		if o.Owner != "" {
			q += " OWNER " + pgIdent(o.Owner)
		}
		if _, err := h.pgExec(ctx, "postgres", q+";"); err != nil {
			return err
		}
		changed = true
	case o.Owner != "" && cur != o.Owner:
		if _, err := h.pgExec(ctx, "postgres", "ALTER DATABASE "+pgIdent(o.Name)+" OWNER TO "+pgIdent(o.Owner)+";"); err != nil {
			return err
		}
		changed = true
	}
	return h.result(map[string]any{"changed": changed})
}

// DatabaseDrop is `falak-db database drop`: drops the database when present (postgres: WITH (FORCE)).
func (h *Helper) DatabaseDrop(ctx context.Context, name string) error {
	if h.Engine.kv() {
		return unsupported(h.Engine, "database drop")
	}
	if err := checkName("--name", name); err != nil {
		return err
	}
	var exists string
	var err error
	if h.Engine.mysqlFamily() {
		exists, err = h.myExec(ctx, "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME="+myLit(name)+";")
	} else {
		exists, err = h.pgExec(ctx, "postgres", "SELECT COUNT(*) FROM pg_database WHERE datname="+pgLit(name)+";")
	}
	if err != nil {
		return err
	}
	if exists == "0" || exists == "" {
		return h.result(map[string]any{"changed": false})
	}
	if h.Engine.mysqlFamily() {
		_, err = h.myExec(ctx, "DROP DATABASE IF EXISTS "+myIdent(name)+";")
	} else {
		_, err = h.pgExec(ctx, "postgres", "DROP DATABASE IF EXISTS "+pgIdent(name)+" WITH (FORCE);")
	}
	if err != nil {
		return err
	}
	return h.result(map[string]any{"changed": true})
}

// UserGrant is one database a user may use.
type UserGrant struct {
	Database   string   `json:"database"`
	Privileges []string `json:"privileges"`
}

// UserSpec is `user apply --spec`'s file: the user's full desired state (grants not listed are revoked).
type UserSpec struct {
	Username string      `json:"username"`
	Password string      `json:"password"`
	Host     string      `json:"host"`
	Grants   []UserGrant `json:"grants"`
	State    string      `json:"state"`
}

func normPrivileges(p []string) ([]string, error) {
	if len(p) == 0 {
		return []string{"ALL PRIVILEGES"}, nil
	}
	out := make([]string, 0, len(p))
	for _, x := range p {
		x = strings.TrimSpace(x)
		if x == "ALL" {
			x = "ALL PRIVILEGES"
		}
		if !privilege.MatchString(x) {
			return nil, usageErr("invalid privilege %q", x)
		}
		out = append(out, x)
	}
	sort.Strings(out)
	return out, nil
}

// readSpecFile reads a file the agent put in the secrets directory (a password or a user spec).
func readSpecFile(path string) ([]byte, error) {
	if path == "" {
		return nil, usageErr("no file given")
	}
	b, err := os.ReadFile(filepath.Clean(path))
	if err != nil {
		return nil, fmt.Errorf("read %s: %w", filepath.Base(path), err)
	}
	return b, nil
}

// UserApply is `falak-db user apply --spec FILE`: converges a user, its password and its grants.
func (h *Helper) UserApply(ctx context.Context, specFile string) error {
	if h.Engine.kv() {
		return unsupported(h.Engine, "user apply")
	}
	b, err := readSpecFile(specFile)
	if err != nil {
		return err
	}
	var s UserSpec
	dec := json.NewDecoder(strings.NewReader(string(b)))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&s); err != nil {
		return usageErr("user spec: %v", err)
	}
	if err := checkName("username", s.Username); err != nil {
		return err
	}
	for _, r := range []string{"root", "postgres", h.pgUser(), "mysql.sys", "mysql.session", "mariadb.sys"} {
		if s.Username == r {
			return usageErr("%s is the engine's own account", s.Username)
		}
	}
	if s.Host == "" {
		s.Host = "%"
	}
	desired := map[string][]string{}
	for _, g := range s.Grants {
		if err := checkName("database", g.Database); err != nil {
			return err
		}
		if desired[g.Database], err = normPrivileges(g.Privileges); err != nil {
			return err
		}
	}
	var changed bool
	if h.Engine.mysqlFamily() {
		changed, err = h.myUserApply(ctx, s, desired)
	} else {
		changed, err = h.pgUserApply(ctx, s, desired)
	}
	if err != nil {
		return err
	}
	return h.result(map[string]any{"changed": changed})
}

func (h *Helper) myUserApply(ctx context.Context, s UserSpec, desired map[string][]string) (bool, error) {
	who := myLit(s.Username) + "@" + myLit(s.Host)
	n, err := h.myExec(ctx, "SELECT COUNT(*) FROM mysql.user WHERE User="+myLit(s.Username)+" AND Host="+myLit(s.Host)+";")
	if err != nil {
		return false, err
	}
	exists := n != "0" && n != ""
	if s.State == "absent" {
		if !exists {
			return false, nil
		}
		_, err := h.myExec(ctx, "DROP USER IF EXISTS "+who+";")
		return err == nil, err
	}
	if s.Password == "" {
		return false, usageErr("password is required when state=present")
	}
	verb := "ALTER"
	if !exists {
		verb = "CREATE"
	}
	if _, err := h.myExec(ctx, verb+" USER "+who+" IDENTIFIED BY "+myLit(s.Password)+";"); err != nil {
		return false, fmt.Errorf("apply user %s: %w", s.Username, err)
	}
	granted, err := h.myExec(ctx, "SELECT DISTINCT TABLE_SCHEMA FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE="+
		myLit("'"+s.Username+"'@'"+s.Host+"'")+";")
	if err != nil {
		return false, err
	}
	var sql strings.Builder
	for _, d := range lines(granted) {
		if _, keep := desired[d]; !keep {
			sql.WriteString("REVOKE ALL PRIVILEGES ON " + myIdent(d) + ".* FROM " + who + ";\n")
		}
	}
	for _, d := range grantKeys(desired) {
		if slicesContain(lines(granted), d) {
			// Revoke first so a narrowed privilege list actually narrows.
			sql.WriteString("REVOKE ALL PRIVILEGES ON " + myIdent(d) + ".* FROM " + who + ";\n")
		}
		sql.WriteString("GRANT " + strings.Join(desired[d], ", ") + " ON " + myIdent(d) + ".* TO " + who + ";\n")
	}
	if sql.Len() > 0 {
		if _, err := h.myExec(ctx, sql.String()); err != nil {
			return false, err
		}
	}
	return true, nil
}

func (h *Helper) pgUserApply(ctx context.Context, s UserSpec, desired map[string][]string) (bool, error) {
	u := pgIdent(s.Username)
	n, err := h.pgExec(ctx, "postgres", "SELECT COUNT(*) FROM pg_roles WHERE rolname="+pgLit(s.Username)+";")
	if err != nil {
		return false, err
	}
	exists := n != "0" && n != ""
	if s.State == "absent" {
		if !exists {
			return false, nil
		}
		_, err := h.pgExec(ctx, "postgres", "DROP ROLE IF EXISTS "+u+";")
		return err == nil, err
	}
	if s.Password == "" {
		return false, usageErr("password is required when state=present")
	}
	verb := "ALTER"
	if !exists {
		verb = "CREATE"
	}
	if _, err := h.pgExec(ctx, "postgres", verb+" ROLE "+u+" WITH LOGIN PASSWORD "+pgLit(s.Password)+";"); err != nil {
		return false, fmt.Errorf("apply user %s: %w", s.Username, err)
	}
	// Databases the role holds an explicit privilege on.
	granted, err := h.pgExec(ctx, "postgres", "SELECT DISTINCT d.datname FROM pg_database d, aclexplode(d.datacl) a "+
		"WHERE a.grantee = (SELECT oid FROM pg_roles WHERE rolname="+pgLit(s.Username)+") AND NOT d.datistemplate ORDER BY 1;")
	if err != nil {
		return false, err
	}
	for _, d := range lines(granted) {
		if _, keep := desired[d]; !keep {
			if _, err := h.pgExec(ctx, "postgres", "REVOKE ALL PRIVILEGES ON DATABASE "+pgIdent(d)+" FROM "+u+";"); err != nil {
				return false, err
			}
		}
	}
	for _, d := range grantKeys(desired) {
		privs := desired[d]
		if len(privs) == 1 && privs[0] == "ALL PRIVILEGES" {
			if _, err := h.pgExec(ctx, "postgres", "GRANT ALL PRIVILEGES ON DATABASE "+pgIdent(d)+" TO "+u+";"); err != nil {
				return false, err
			}
			if _, err := h.pgExec(ctx, d, pgSchemaGrants(s.Username, "ALL", "ALL", true)); err != nil {
				return false, err
			}
			continue
		}
		pl := strings.Join(privs, ", ")
		if _, err := h.pgExec(ctx, "postgres", "GRANT CONNECT ON DATABASE "+pgIdent(d)+" TO "+u+";"); err != nil {
			return false, err
		}
		if _, err := h.pgExec(ctx, d, pgSchemaGrants(s.Username, "USAGE", pl, false)); err != nil {
			return false, err
		}
	}
	return true, nil
}

// PasswordSet is `falak-db password set --file FILE`: the superuser's (postgres), root's (mysql/mariadb) or the
// default user's (redis/valkey) new password. falak-db connects with the current password file; the agent swaps the
// file afterwards, so the next start uses the new one.
//
// It is idempotent: when the engine already took the new password (a retry after the agent could not swap the file),
// MySQL/MariaDB connect with the new one instead. Redis/Valkey with keepCurrent add the new password next to the
// current one (both valid until a later `password set` without it), so apps keep connecting until they are redeployed.
func (h *Helper) PasswordSet(ctx context.Context, file string, keepCurrent bool) error {
	b, err := readSpecFile(file)
	if err != nil {
		return err
	}
	pw := strings.TrimRight(string(b), "\r\n")
	if pw == "" {
		return usageErr("the password file is empty")
	}
	if keepCurrent && !h.Engine.kv() {
		return usageErr("--keep-current is for redis/valkey")
	}
	switch {
	case h.Engine == Postgres:
		_, err = h.pgExec(ctx, "postgres", "ALTER ROLE "+pgIdent(h.pgUser())+" WITH PASSWORD "+pgLit(pw)+";")
	case h.Engine.mysqlFamily():
		const q = "SELECT Host FROM mysql.user WHERE User='root';"
		exec := h.myExec
		hosts, qerr := exec(ctx, q)
		if qerr != nil {
			// The engine may have the new password already (a retry): connect with it.
			exec = func(ctx context.Context, sql string) (string, error) { return h.myExecWith(ctx, pw, sql) }
			if hosts, err = exec(ctx, q); err != nil {
				err = qerr
			}
		}
		if err == nil {
			var sql strings.Builder
			for _, host := range lines(hosts) {
				sql.WriteString("ALTER USER 'root'@" + myLit(host) + " IDENTIFIED BY " + myLit(pw) + ";\n")
			}
			if sql.Len() == 0 {
				return fmt.Errorf("no root account")
			}
			_, err = exec(ctx, sql.String())
		}
	default:
		// Only hashes reach the server (and argv); the ACL file is rewritten the way `init` renders it.
		sum := sha256.Sum256([]byte(pw))
		hash := hex.EncodeToString(sum[:])
		hashes := "#" + hash
		args := []string{"ACL", "SETUSER", "default", "resetpass", "#" + hash}
		if keepCurrent {
			cur, rerr := readPassword(h.Engine, h.Env)
			if rerr != nil {
				return rerr
			}
			cs := sha256.Sum256([]byte(cur))
			if c := hex.EncodeToString(cs[:]); c != hash {
				hashes = "#" + c + " " + hashes
			}
			args = []string{"ACL", "SETUSER", "default", "#" + hash}
		}
		if _, err = h.kvCmd(ctx, args...); err == nil {
			acl := "user default on " + hashes + " ~* &* +@all -config -debug -module\n"
			if err = writeFileAtomic(h.path(kvACLPath), []byte(acl), 0o600); err == nil {
				err = h.chown(h.path(kvACLPath))
			}
		}
	}
	if err != nil {
		return fmt.Errorf("password set: %w", err)
	}
	return h.result(map[string]any{"changed": true})
}

func lines(s string) []string {
	var out []string
	for _, l := range strings.Split(s, "\n") {
		if l = strings.TrimSpace(l); l != "" {
			out = append(out, l)
		}
	}
	return out
}

func grantKeys(m map[string][]string) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	sort.Strings(out)
	return out
}

func slicesContain(list []string, s string) bool {
	for _, x := range list {
		if x == s {
			return true
		}
	}
	return false
}
