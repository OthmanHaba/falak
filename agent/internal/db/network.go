package db

import (
	"context"
	"fmt"
	"path/filepath"
	"sort"
	"strings"

	"github.com/kiln/agent/internal/runner"
)

// Remote access for dedicated database servers (db.user.apply `remote`).
//
// Out of the box both engines only listen on localhost, so a database server is unreachable from the
// app servers that use it. For users flagged remote the engine is made to listen on every interface
// and (PostgreSQL) a password-authenticated host rule is kept per remote user. Who can reach the port
// is still decided by the server firewall, which only allows SSH until a rule opens the database port.

const (
	hbaBegin = "# BEGIN kiln remote users (managed by the Kiln agent; do not edit)"
	hbaEnd   = "# END kiln remote users"

	pgListenFile = "conf.d/90-kiln-network.conf"
	pgListenConf = "# Managed by the Kiln agent: accept connections from other servers (the firewall decides who).\nlisten_addresses = '*'\n"

	myNetworkConf = "# Managed by the Kiln agent: accept connections from other servers (the firewall decides who).\n[mysqld]\nbind-address = 0.0.0.0\n"
)

// pgClusterDir is the configuration directory of the newest Debian/Ubuntu PostgreSQL cluster
// (/etc/postgresql/<version>/main), as a host path.
func (db *DB) pgClusterDir() (string, error) {
	matches, err := filepath.Glob(db.d.FS.P("/etc/postgresql/*/main/postgresql.conf"))
	if err != nil {
		return "", err
	}
	if len(matches) == 0 {
		return "", fmt.Errorf("no PostgreSQL cluster configuration under /etc/postgresql")
	}
	versions := make([]string, 0, len(matches))
	for _, m := range matches {
		versions = append(versions, filepath.Base(filepath.Dir(filepath.Dir(m))))
	}
	sort.Slice(versions, func(i, j int) bool { return versionLess(versions[i], versions[j]) })
	return "/etc/postgresql/" + versions[len(versions)-1] + "/main", nil
}

// versionLess orders "9.6" < "16" < "17" numerically.
func versionLess(a, b string) bool {
	pa, pb := strings.Split(a, "."), strings.Split(b, ".")
	for i := 0; i < len(pa) && i < len(pb); i++ {
		var x, y int
		fmt.Sscanf(pa[i], "%d", &x)
		fmt.Sscanf(pb[i], "%d", &y)
		if x != y {
			return x < y
		}
	}
	return len(pa) < len(pb)
}

// hbaEntry is one managed pg_hba.conf host rule: user from source (a CIDR).
type hbaEntry struct{ user, source string }

// syncRemoteAccess converges engine-level network access with the remote and container users in states.
// It is a no-op for an engine without such users (a server whose engine was never exposed stays on localhost).
func (db *DB) syncRemoteAccess(ctx context.Context, e engine, states map[string]userState) error {
	var entries []hbaEntry
	exposed := false
	for _, key := range sortedKeys(states) {
		s := states[key]
		if !strings.HasPrefix(key, e.name+"/") || (!s.Remote && len(s.Containers) == 0 && s.ContainerOf == "") {
			continue
		}
		exposed = true
		name, _, _ := strings.Cut(strings.TrimPrefix(key, e.name+"/"), "@")
		if s.Remote {
			entries = append(entries, hbaEntry{name, "0.0.0.0/0"}, hbaEntry{name, "::/0"})
		}
		for _, c := range s.Containers {
			entries = append(entries, hbaEntry{name, c})
		}
	}
	if e.name == "mysql" {
		if !exposed {
			return nil
		}
		return db.mysqlListen(ctx)
	}
	return db.pgRemote(ctx, entries)
}

func (db *DB) pgRemote(ctx context.Context, users []hbaEntry) error {
	dir, err := db.pgClusterDir()
	if err != nil {
		if len(users) == 0 {
			return nil
		}
		return err
	}
	hbaPath := dir + "/pg_hba.conf"
	cur, err := db.d.FS.ReadFile(hbaPath)
	if err != nil {
		return fmt.Errorf("read pg_hba.conf: %w", err)
	}
	next := withHBABlock(string(cur), users)
	if len(users) == 0 && next == string(cur) {
		return nil
	}

	listenChanged := false
	if len(users) > 0 {
		if listenChanged, err = db.d.FS.WriteFile(dir+"/"+pgListenFile, []byte(pgListenConf), 0o644); err != nil {
			return fmt.Errorf("write %s: %w", pgListenFile, err)
		}
	}
	hbaChanged := false
	if next != string(cur) {
		if _, err := db.d.FS.WriteFile(hbaPath, []byte(next), 0o640); err != nil {
			return fmt.Errorf("write pg_hba.conf: %w", err)
		}
		// PostgreSQL reads it as its own user (Debian ships it postgres:postgres 0640).
		if db.d.FS.IsReal() {
			if err := db.d.FS.Chown(hbaPath, "postgres", "postgres"); err != nil {
				return fmt.Errorf("chown pg_hba.conf: %w", err)
			}
		}
		hbaChanged = true
	}
	switch {
	case listenChanged: // listen_addresses only applies on restart
		_, err = runner.Check(ctx, db.d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"restart", "postgresql"}})
	case hbaChanged:
		_, err = runner.Check(ctx, db.d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"reload", "postgresql"}})
	}
	if err != nil {
		return fmt.Errorf("apply PostgreSQL network access: %w", err)
	}
	return nil
}

// withHBABlock replaces (or appends, or removes when users is empty) the managed block of pg_hba.conf.
// Each entry lets its user connect from its source to any database it has grants on, with scram-sha-256.
func withHBABlock(content string, users []hbaEntry) string {
	var kept []string
	in := false
	for _, line := range strings.Split(strings.TrimRight(content, "\n"), "\n") {
		switch {
		case strings.TrimSpace(line) == hbaBegin:
			in = true
		case strings.TrimSpace(line) == hbaEnd:
			in = false
		case !in:
			kept = append(kept, line)
		}
	}
	out := strings.TrimRight(strings.Join(kept, "\n"), "\n")
	if len(users) > 0 {
		var b strings.Builder
		b.WriteString(out)
		b.WriteString("\n\n" + hbaBegin + "\n")
		for _, u := range users {
			fmt.Fprintf(&b, "host    all    %s    %-12s scram-sha-256\n", u.user, u.source)
		}
		b.WriteString(hbaEnd)
		out = b.String()
	}
	return strings.TrimRight(out, "\n") + "\n"
}

// mysqlListen makes MySQL/MariaDB listen on every interface (Ubuntu binds 127.0.0.1 by default).
// The drop-in sorts after the distribution's mysqld.cnf / 50-server.cnf so its bind-address wins.
func (db *DB) mysqlListen(ctx context.Context) error {
	path, service := "/etc/mysql/mysql.conf.d/zz-kiln-network.cnf", "mysql"
	switch {
	case db.d.FS.Exists("/etc/mysql/mariadb.conf.d"):
		path, service = "/etc/mysql/mariadb.conf.d/zz-kiln-network.cnf", "mariadb"
	case !db.d.FS.Exists("/etc/mysql/mysql.conf.d"):
		path = "/etc/mysql/conf.d/zz-kiln-network.cnf"
	}
	changed, err := db.d.FS.WriteFile(path, []byte(myNetworkConf), 0o644)
	if err != nil {
		return fmt.Errorf("write %s: %w", path, err)
	}
	if !changed {
		return nil
	}
	if _, err := runner.Check(ctx, db.d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"restart", service}}); err != nil {
		return fmt.Errorf("apply MySQL network access: %w", err)
	}
	return nil
}
