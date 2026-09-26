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
		case strings.Contains(q, "FROM mysql.user") || strings.Contains(q, "FROM pg_roles"):
			if s.users[between(q, "='", "'")] {
				out = "1"
			} else {
				out = "0"
			}
		case strings.HasPrefix(q, "CREATE USER") || strings.HasPrefix(q, "CREATE ROLE"):
			s.users[strings.Trim(strings.SplitN(strings.Fields(q)[2], "@", 2)[0], "'\"")] = true
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
