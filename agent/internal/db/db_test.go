package db

import (
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/hostfs"
	"github.com/kiln/agent/internal/runner"
	"github.com/kiln/agent/internal/runner/runnertest"
)

// sqlSim fakes a server: remembers databases and users from the SQL it sees on stdin.
type sqlSim struct {
	dbs   map[string]bool
	users map[string]bool
	sql   []string
}

func newSim(f *runnertest.Fake, client string) *sqlSim {
	s := &sqlSim{dbs: map[string]bool{}, users: map[string]bool{}}
	f.OnFunc(client, func(c runnertest.Call) (runner.Result, error) {
		q := c.Stdin
		s.sql = append(s.sql, q)
		out := ""
		switch {
		case strings.Contains(q, "SCHEMATA WHERE") || strings.Contains(q, "FROM pg_database WHERE datname="):
			name := between(q, "='", "'")
			if strings.HasPrefix(q, "SELECT pg_get_userbyid") {
				out = "postgres"
			} else if s.dbs[name] {
				out = name
			}
		case strings.HasPrefix(q, "CREATE DATABASE"):
			s.dbs[strings.Trim(strings.Fields(q)[2], "`\"")] = true
			if strings.Contains(q, "IF NOT EXISTS") {
				s.dbs[strings.Trim(strings.Fields(q)[5], "`\"")] = true
			}
		case strings.HasPrefix(q, "DROP DATABASE"):
			for k := range s.dbs {
				if strings.Contains(q, k) {
					delete(s.dbs, k)
				}
			}
		case strings.Contains(q, "FROM mysql.user"): // MySQL accounts are user@host
			if s.users[between(q, "User='", "'")+"@"+between(q, "Host='", "'")] {
				out = "1"
			} else {
				out = "0"
			}
		case strings.Contains(q, "FROM pg_roles"):
			if s.users[between(q, "='", "'")] {
				out = "1"
			} else {
				out = "0"
			}
		case strings.HasPrefix(q, "CREATE USER"):
			s.users[strings.ReplaceAll(strings.Fields(q)[2], "'", "")] = true
		case strings.HasPrefix(q, "DROP USER"):
			delete(s.users, strings.TrimSuffix(strings.ReplaceAll(strings.Fields(q)[4], "'", ""), ";"))
		case strings.HasPrefix(q, "CREATE ROLE"):
			s.users[strings.Trim(strings.Fields(q)[2], "\"")] = true
		case strings.HasPrefix(q, "REVOKE ALL PRIVILEGES ON `") && strings.Contains(q, "GRANT"):
			return runner.Result{ExitCode: 1, Stderr: []byte("ERROR 1141 (42000): There is no such grant defined")}, nil
		}
		return runner.Result{Stdout: []byte(out + "\n")}, nil
	})
	return s
}

func between(s, a, b string) string {
	i := strings.Index(s, a)
	if i < 0 {
		return ""
	}
	s = s[i+len(a):]
	j := strings.Index(s, b)
	if j < 0 {
		return s
	}
	return s[:j]
}

func newDB(t *testing.T, f *runnertest.Fake, client *http.Client) (*DB, string) {
	root := t.TempDir()
	return New(Deps{Runner: f, FS: hostfs.FS{Root: root}, HTTP: client, TempDir: t.TempDir()}), root
}

var st = commands.NewTestStream("c", &commands.Collector{})

func TestCreateDropIdempotent(t *testing.T) {
	for _, eng := range []string{"mysql", "postgres"} {
		f := &runnertest.Fake{}
		cli := map[string]string{"mysql": "mysql", "postgres": "psql"}[eng]
		sim := newSim(f, cli)
		db, _ := newDB(t, f, nil)
		r, err := db.Create(context.Background(), CreatePayload{Engine: eng, Name: "shop"}, st)
		if err != nil || !r.(ChangedResult).Changed || !sim.dbs["shop"] {
			t.Fatal(eng, r, err, sim.sql)
		}
		r, _ = db.Create(context.Background(), CreatePayload{Engine: eng, Name: "shop"}, st)
		if r.(ChangedResult).Changed {
			t.Fatal(eng, "create not idempotent")
		}
		if eng == "postgres" {
			for _, c := range f.Calls() {
				if c.User != "postgres" {
					t.Fatal("psql not run as postgres")
				}
			}
		}
		r, _ = db.Drop(context.Background(), DropPayload{Engine: eng, Name: "shop"}, st)
		r2, _ := db.Drop(context.Background(), DropPayload{Engine: eng, Name: "shop"}, st)
		if !r.(ChangedResult).Changed || r2.(ChangedResult).Changed {
			t.Fatal(eng, "drop")
		}
		if _, err := db.Create(context.Background(), CreatePayload{Engine: eng, Name: "x; DROP"}, st); !commands.IsPayloadError(err) {
			t.Fatal("bad ident accepted")
		}
	}
}

func TestUserApplyMySQL(t *testing.T) {
	f := &runnertest.Fake{}
	sim := newSim(f, "mysql")
	db, _ := newDB(t, f, nil)
	p := UserPayload{Engine: "mysql", Username: "shop", Password: "s3cr'et", Grants: []Grant{{Database: "shop", Privileges: []string{"ALL"}}, {Database: "old"}}}
	r, err := db.UserApply(context.Background(), p, st)
	if err != nil || !r.(ChangedResult).Changed {
		t.Fatal(r, err)
	}
	all := strings.Join(sim.sql, "\n")
	if !strings.Contains(all, "CREATE USER 'shop'@'%' IDENTIFIED BY 's3cr''et';") || !strings.Contains(all, "GRANT ALL PRIVILEGES ON `shop`.* TO 'shop'@'%';") {
		t.Fatal(all)
	}
	for _, c := range f.Calls() {
		if strings.Contains(c.Line, "s3cr") {
			t.Fatal("password in argv")
		}
	}
	// same desired state → no mutation
	sim.sql = nil
	r, _ = db.UserApply(context.Background(), p, st)
	if r.(ChangedResult).Changed || len(sim.sql) != 1 {
		t.Fatal("not idempotent", sim.sql)
	}
	// password change → ALTER; dropped grant → REVOKE
	p.Password = "new"
	p.Grants = p.Grants[:1]
	sim.sql = nil
	r, _ = db.UserApply(context.Background(), p, st)
	all = strings.Join(sim.sql, "\n")
	if !r.(ChangedResult).Changed || !strings.Contains(all, "ALTER USER 'shop'@'%' IDENTIFIED BY 'new'") || !strings.Contains(all, "REVOKE ALL PRIVILEGES ON `old`.* FROM 'shop'@'%';") {
		t.Fatal(all)
	}
	p.State = "absent"
	r, _ = db.UserApply(context.Background(), p, st)
	if !r.(ChangedResult).Changed || !strings.Contains(strings.Join(sim.sql, "\n"), "DROP USER IF EXISTS 'shop'@'%'") {
		t.Fatal("absent")
	}
}

func TestUserApplyPostgres(t *testing.T) {
	f := &runnertest.Fake{}
	sim := newSim(f, "psql")
	db, root := newDB(t, f, nil)
	p := UserPayload{Engine: "postgres", Username: "app", Password: "pw", Grants: []Grant{{Database: "app", Privileges: []string{"SELECT", "INSERT"}}}}
	if _, err := db.UserApply(context.Background(), p, st); err != nil {
		t.Fatal(err)
	}
	all := strings.Join(sim.sql, "\n")
	for _, w := range []string{`CREATE ROLE "app" WITH LOGIN PASSWORD 'pw';`, `GRANT CONNECT ON DATABASE "app" TO "app";`, `GRANT INSERT, SELECT ON ALL TABLES IN SCHEMA public TO "app";`} {
		if !strings.Contains(all, w) {
			t.Fatal(w, all)
		}
	}
	fi, _ := os.Stat(filepath.Join(root, "var/lib/kiln/db/users.json"))
	if fi.Mode().Perm() != 0o600 {
		t.Fatal(fi.Mode())
	}
	b, _ := os.ReadFile(filepath.Join(root, "var/lib/kiln/db/users.json"))
	if strings.Contains(string(b), `"pw"`) {
		t.Fatal("plaintext password stored")
	}
}

func TestBackupLocalPresignedAndRestore(t *testing.T) {
	dump := "CREATE TABLE t (id int);\nINSERT INTO t VALUES (1);\n"
	f := &runnertest.Fake{}
	f.On("mysqldump", runner.Result{Stdout: []byte(dump)})
	var restored string
	f.OnFunc("mysql --batch --skip-column-names --default-character-set=utf8mb4 shop", func(c runnertest.Call) (runner.Result, error) {
		restored = c.Stdin
		return runner.Result{}, nil
	})
	newSim(f, "mysql")
	var uploaded []byte
	var gotLen int64
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.Method {
		case http.MethodPut:
			gotLen = r.ContentLength
			uploaded, _ = io.ReadAll(r.Body)
		case http.MethodGet:
			w.Write(uploaded)
		}
	}))
	defer srv.Close()
	db, root := newDB(t, f, srv.Client())

	r, err := db.Backup(context.Background(), BackupPayload{Engine: "mysql", Database: "shop", Destination: Location{Kind: "local", Path: "/var/backups/shop.sql.gz"}}, st)
	if err != nil {
		t.Fatal(err)
	}
	br := r.(BackupResult)
	raw, _ := os.ReadFile(filepath.Join(root, "var/backups/shop.sql.gz"))
	sum := sha256.Sum256(raw)
	if br.SizeBytes != int64(len(raw)) || br.SHA256 != hex.EncodeToString(sum[:]) || br.Location != "/var/backups/shop.sql.gz" {
		t.Fatalf("%+v", br)
	}
	zr, _ := gzip.NewReader(bytes.NewReader(raw))
	if plain, _ := io.ReadAll(zr); string(plain) != dump {
		t.Fatal("dump content", string(plain))
	}
	if fi, _ := os.Stat(filepath.Join(root, "var/backups/shop.sql.gz")); fi.Mode().Perm() != 0o600 {
		t.Fatal("backup mode", fi.Mode())
	}

	r, err = db.Backup(context.Background(), BackupPayload{Engine: "mysql", Database: "shop", Destination: Location{Kind: "presigned_url", URL: srv.URL + "/b/shop.sql.gz?X-Amz-Signature=secret"}}, st)
	if err != nil {
		t.Fatal(err)
	}
	br = r.(BackupResult)
	if gotLen != br.SizeBytes || int64(len(uploaded)) != br.SizeBytes || strings.Contains(br.Location, "secret") {
		t.Fatalf("%+v len=%d", br, gotLen)
	}

	// restore from URL with sha verification
	rr, err := db.Restore(context.Background(), RestorePayload{Engine: "mysql", Database: "shop", Source: Location{Kind: "url", URL: srv.URL + "/b/shop.sql.gz"}, SHA256: br.SHA256}, st)
	if err != nil {
		t.Fatal(err)
	}
	if restored != dump || rr.(RestoreResult).Bytes != int64(len(dump)) {
		t.Fatalf("restored %q", restored)
	}
	// local restore with wrong sha is refused
	if _, err := db.Restore(context.Background(), RestorePayload{Engine: "mysql", Database: "shop", Source: Location{Kind: "local", Path: "/var/backups/shop.sql.gz"}, SHA256: strings.Repeat("0", 64)}, st); err == nil {
		t.Fatal("sha mismatch accepted")
	}
	restored = ""
	if _, err := db.Restore(context.Background(), RestorePayload{Engine: "mysql", Database: "shop", Source: Location{Kind: "local", Path: "/var/backups/shop.sql.gz"}}, st); err != nil || restored != dump {
		t.Fatal(err, restored)
	}
}

func TestBackupDumpFailureLeavesNoFile(t *testing.T) {
	f := (&runnertest.Fake{}).On("pg_dump", runner.Result{ExitCode: 1, Stderr: []byte("no such db")})
	db, root := newDB(t, f, nil)
	_, err := db.Backup(context.Background(), BackupPayload{Engine: "postgres", Database: "x", Destination: Location{Kind: "local", Path: "/b/x.sql.gz"}}, st)
	if err == nil {
		t.Fatal("expected error")
	}
	if _, err := os.Stat(filepath.Join(root, "b/x.sql.gz")); err == nil {
		t.Fatal("file left behind")
	}
	if c := f.Calls()[0]; c.User != "postgres" {
		t.Fatal("pg_dump user")
	}
}

const debianHBA = "local   all             postgres                                peer\nlocal   all             all                                     peer\nhost    all             all             127.0.0.1/32            scram-sha-256\n"

func TestUserApplyRemotePostgresOpensNetworkAccess(t *testing.T) {
	f := &runnertest.Fake{}
	newSim(f, "psql")
	f.On("systemctl", runner.Result{})
	db, root := newDB(t, f, nil)
	for _, v := range []string{"14", "16"} {
		dir := filepath.Join(root, "etc/postgresql", v, "main")
		os.MkdirAll(dir, 0o755)
		os.WriteFile(filepath.Join(dir, "postgresql.conf"), []byte("#listen_addresses = 'localhost'\n"), 0o644)
		os.WriteFile(filepath.Join(dir, "pg_hba.conf"), []byte(debianHBA), 0o640)
	}
	hba := func() string {
		b, _ := os.ReadFile(filepath.Join(root, "etc/postgresql/16/main/pg_hba.conf"))
		return string(b)
	}
	restarts := func(verb string) int {
		n := 0
		for _, l := range f.Lines() {
			if l == "systemctl "+verb+" postgresql" {
				n++
			}
		}
		return n
	}

	p := UserPayload{Engine: "postgres", Username: "shop", Password: "pw", Remote: true, Grants: []Grant{{Database: "shop"}}}
	if _, err := db.UserApply(context.Background(), p, st); err != nil {
		t.Fatal(err)
	}
	conf, err := os.ReadFile(filepath.Join(root, "etc/postgresql/16/main/conf.d/90-kiln-network.conf"))
	if err != nil || !strings.Contains(string(conf), "listen_addresses = '*'") {
		t.Fatal("listen_addresses not set on the newest cluster", string(conf), err)
	}
	if _, err := os.Stat(filepath.Join(root, "etc/postgresql/14/main/conf.d")); err == nil {
		t.Fatal("older cluster touched")
	}
	if h := hba(); !strings.HasPrefix(h, debianHBA) || !strings.Contains(h, "host    all    shop    0.0.0.0/0    scram-sha-256") || !strings.Contains(h, "host    all    shop    ::/0") {
		t.Fatal(h)
	}
	if restarts("restart") != 1 {
		t.Fatal("listen_addresses needs a restart", f.Lines())
	}

	// Re-applying the same state touches nothing.
	if _, err := db.UserApply(context.Background(), p, st); err != nil {
		t.Fatal(err)
	}
	if restarts("restart") != 1 || restarts("reload") != 0 {
		t.Fatal("not idempotent", f.Lines())
	}

	// A second remote user only reloads (pg_hba.conf); the block stays single and sorted.
	if _, err := db.UserApply(context.Background(), UserPayload{Engine: "postgres", Username: "analytics", Password: "pw2", Remote: true}, st); err != nil {
		t.Fatal(err)
	}
	h := hba()
	if strings.Count(h, hbaBegin) != 1 || strings.Index(h, " analytics ") > strings.Index(h, " shop ") || restarts("reload") != 1 {
		t.Fatal(h, f.Lines())
	}

	// Dropping users removes their rules; the last one removes the block.
	p.State = "absent"
	db.UserApply(context.Background(), p, st)
	db.UserApply(context.Background(), UserPayload{Engine: "postgres", Username: "analytics", State: "absent"}, st)
	if h := hba(); h != debianHBA {
		t.Fatalf("block not removed:\n%s", h)
	}
}

func TestUserApplyLocalPostgresLeavesEngineOnLocalhost(t *testing.T) {
	f := &runnertest.Fake{}
	newSim(f, "psql")
	db, root := newDB(t, f, nil)
	dir := filepath.Join(root, "etc/postgresql/16/main")
	os.MkdirAll(dir, 0o755)
	os.WriteFile(filepath.Join(dir, "postgresql.conf"), nil, 0o644)
	os.WriteFile(filepath.Join(dir, "pg_hba.conf"), []byte(debianHBA), 0o640)
	if _, err := db.UserApply(context.Background(), UserPayload{Engine: "postgres", Username: "app", Password: "pw"}, st); err != nil {
		t.Fatal(err)
	}
	if f.Ran("systemctl") || fileExists(filepath.Join(dir, "conf.d/90-kiln-network.conf")) {
		t.Fatal("local user exposed the engine", f.Lines())
	}
}

func TestUserApplyRemoteMySQLBindsAllInterfaces(t *testing.T) {
	f := &runnertest.Fake{}
	newSim(f, "mysql")
	f.On("systemctl", runner.Result{})
	db, root := newDB(t, f, nil)
	os.MkdirAll(filepath.Join(root, "etc/mysql/mysql.conf.d"), 0o755)
	p := UserPayload{Engine: "mysql", Username: "shop", Password: "pw", Remote: true}
	db.UserApply(context.Background(), p, st)
	db.UserApply(context.Background(), p, st)
	b, err := os.ReadFile(filepath.Join(root, "etc/mysql/mysql.conf.d/zz-kiln-network.cnf"))
	if err != nil || !strings.Contains(string(b), "bind-address = 0.0.0.0") {
		t.Fatal(string(b), err)
	}
	n := 0
	for _, l := range f.Lines() {
		if l == "systemctl restart mysql" {
			n++
		}
	}
	if n != 1 {
		t.Fatal("expected exactly one restart", f.Lines())
	}
}

func fileExists(p string) bool { _, err := os.Stat(p); return err == nil }

// Containers on the server reach a localhost engine through the host address: PostgreSQL gets host rules for the
// Docker ranges only (the firewall lets just the Docker bridges in), not the whole internet.
func TestUserApplyContainersPostgres(t *testing.T) {
	f := &runnertest.Fake{}
	newSim(f, "psql")
	f.On("systemctl", runner.Result{})
	db, root := newDB(t, f, nil)
	dir := filepath.Join(root, "etc/postgresql/16/main")
	os.MkdirAll(dir, 0o755)
	os.WriteFile(filepath.Join(dir, "postgresql.conf"), nil, 0o644)
	os.WriteFile(filepath.Join(dir, "pg_hba.conf"), []byte(debianHBA), 0o640)

	p := UserPayload{Engine: "postgres", Username: "app", Password: "pw", Containers: []string{"172.16.0.0/12", "192.168.0.0/16"}}
	if _, err := db.UserApply(context.Background(), p, st); err != nil {
		t.Fatal(err)
	}
	b, _ := os.ReadFile(filepath.Join(dir, "pg_hba.conf"))
	h := string(b)
	for _, w := range []string{"host    all    app    172.16.0.0/12 scram-sha-256", "host    all    app    192.168.0.0/16 scram-sha-256"} {
		if !strings.Contains(h, w) {
			t.Fatalf("missing %q in\n%s", w, h)
		}
	}
	if strings.Contains(h, "0.0.0.0/0") || !fileExists(filepath.Join(dir, "conf.d/90-kiln-network.conf")) {
		t.Fatal("expected container ranges only, listening on every interface", h)
	}
	r, _ := db.UserApply(context.Background(), p, st)
	if r.(ChangedResult).Changed {
		t.Fatal("re-applying the same state changed something")
	}

	// Turning container access off removes the rules again.
	p.Containers = nil
	if r, _ := db.UserApply(context.Background(), p, st); !r.(ChangedResult).Changed {
		t.Fatal("expected a change")
	}
	if b, _ := os.ReadFile(filepath.Join(dir, "pg_hba.conf")); string(b) != debianHBA {
		t.Fatalf("rules left behind:\n%s", b)
	}

	if _, err := db.UserApply(context.Background(), UserPayload{Engine: "postgres", Username: "app", Password: "pw", Containers: []string{"172.16.0.1/12"}}, st); !commands.IsPayloadError(err) {
		t.Fatal("accepted a host address as a range", err)
	}
}

// MySQL matches accounts by host: each container range is an extra account with the same password and grants.
func TestUserApplyContainersMySQL(t *testing.T) {
	f := &runnertest.Fake{}
	sim := newSim(f, "mysql")
	f.On("systemctl", runner.Result{})
	db, root := newDB(t, f, nil)
	os.MkdirAll(filepath.Join(root, "etc/mysql/mysql.conf.d"), 0o755)

	p := UserPayload{Engine: "mysql", Username: "app", Password: "pw", Host: "localhost", Grants: []Grant{{Database: "shop"}}, Containers: []string{"172.16.0.0/12"}}
	if _, err := db.UserApply(context.Background(), p, st); err != nil {
		t.Fatal(err)
	}
	if !sim.users["app@localhost"] || !sim.users["app@172.16.0.0/255.240.0.0"] {
		t.Fatal("accounts", sim.users)
	}
	all := strings.Join(sim.sql, "\n")
	if !strings.Contains(all, "TO 'app'@'172.16.0.0/255.240.0.0'") {
		t.Fatal("container account has no grants:\n" + all)
	}
	if b, err := os.ReadFile(filepath.Join(root, "etc/mysql/mysql.conf.d/zz-kiln-network.cnf")); err != nil || !strings.Contains(string(b), "bind-address = 0.0.0.0") {
		t.Fatal("MySQL still bound to localhost", err)
	}

	// Removing the user removes its container accounts too.
	p.State = "absent"
	if _, err := db.UserApply(context.Background(), p, st); err != nil {
		t.Fatal(err)
	}
	if sim.users["app@localhost"] || sim.users["app@172.16.0.0/255.240.0.0"] {
		t.Fatal("accounts left behind", sim.users)
	}
}
