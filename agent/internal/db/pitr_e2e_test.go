//go:build pitre2e

package db

// The whole point-in-time recovery flow against real containers (images/db/pitr-test.sh runs it): a source instance
// with PITR on, rows inserted, a base backup, more rows, a time T, DROP TABLE, the spool shipped through a fake
// control plane to a fake S3 (httptest), then a restore to T into a new instance: the dropped rows are back, the copy
// is read-only until promoted.
//
//	FALAK_PITR_ENGINE=postgres FALAK_PITR_VERSION=17 FALAK_PITR_IMAGE=localhost:5000/falak-postgres:17 \
//	FALAK_PITR_DIGEST=sha256:… go test -tags pitre2e -run TestPITRFlowEndToEnd -v ./internal/db

import (
	"bytes"
	"context"
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/backupcrypt"
	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// noFirewall runs everything but iptables (a laptop has none; the rules are tested elsewhere).
type noFirewall struct{ runner.Runner }

func (r noFirewall) Run(ctx context.Context, c runner.Cmd) (runner.Result, error) {
	if c.Name == "iptables" {
		return runner.Result{}, nil
	}
	return r.Runner.Run(ctx, c)
}

func e2eEnv(t *testing.T, k string) string {
	v := os.Getenv(k)
	if v == "" {
		t.Skipf("%s is not set (images/db/pitr-test.sh sets it)", k)
	}
	return v
}

func TestPITRFlowEndToEnd(t *testing.T) {
	engine, version, image, digest := e2eEnv(t, "FALAK_PITR_ENGINE"), e2eEnv(t, "FALAK_PITR_VERSION"), e2eEnv(t, "FALAK_PITR_IMAGE"), e2eEnv(t, "FALAK_PITR_DIGEST")
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Minute)
	defer cancel()
	// Under the home directory: Docker Desktop shares it with its VM.
	root, err := os.MkdirTemp(os.Getenv("FALAK_PITR_WORK"), "pitr-e2e-")
	if err != nil {
		t.Fatal(err)
	}
	store := newObjectStore(t)
	dock := docker.NewClient(os.Getenv("FALAK_PITR_DOCKER_SOCK"))
	log := slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelWarn}))
	d := New(Deps{Runner: noFirewall{runner.Exec{}}, Docker: dock, FS: hostfs.FS{}, Logger: log, HTTP: store.srv.Client(),
		VolumesRoot: filepath.Join(root, "volumes"), SecretsDir: filepath.Join(root, "secrets"), EtcDir: filepath.Join(root, "etc"),
		TempDir: filepath.Join(root, "staging"), Mounted: func(string) bool { return true }, Poll: time.Second, HealthWait: 5 * time.Minute})
	cp := newFakeCP(store)
	ship := d.NewShipper(cp)
	src, dst := "01hzye2e00000000000000000"+fmt.Sprint(time.Now().Unix()%10), "01hzye2e10000000000000000"+fmt.Sprint(time.Now().Unix()%10)
	t.Cleanup(func() {
		bg := context.Background()
		for _, id := range []string{src, dst} {
			_, _ = d.InstanceDelete(bg, IDPayload{ID: id}, stream())
		}
		if os.Getenv("FALAK_PITR_KEEP") == "" {
			_, _ = runner.Exec{}.Run(bg, runner.Cmd{Name: "rm", Args: []string{"-rf", root}})
		}
	})
	spec := func(id, vol string, pitr bool) InstanceSpec {
		return InstanceSpec{ID: id, Engine: engine, Version: version, Image: image, Digest: digest, VolumeID: vol, MemoryBytes: 1 << 30,
			PITR: &PITRSpec{Enabled: pitr}}
	}
	password := "e2e-Pa55word-" + fmt.Sprint(time.Now().UnixNano()%100000)

	t.Logf("starting the source instance (%s %s)", engine, version)
	if _, err := d.InstanceCreate(ctx, InstancePayload{Instance: spec(src, "01hzye2evol000000000000001", true), Password: password}, stream()); err != nil {
		t.Fatal(err)
	}
	// The application's account (MariaDB's read_only holds back every account but those with READ ONLY ADMIN, root).
	appUser := "app_rw"
	asApp := false
	sql := func(id, q string) (string, error) {
		var argv []string
		switch engine {
		case "postgres":
			argv = []string{"exec", Container(id), "psql", "-U", "postgres", "-d", "app", "-tAX", "-v", "ON_ERROR_STOP=1", "-c", q}
		default:
			client := "mysql"
			if engine == "mariadb" {
				client = "mariadb --skip-ssl" // no certificate here; its client requires TLS by default
			}
			login := ` -uroot -p"$(cat /run/secrets/password)"`
			if asApp {
				login = " -u" + appUser + " -papp-Pa55word"
			}
			argv = []string{"exec", Container(id), "sh", "-c", client + login + ` -N -B app -e "$0"`, q}
		}
		var out, errb bytes.Buffer
		res, err := runner.Exec{}.Run(ctx, runner.Cmd{Name: "docker", Args: argv, Stdout: &out, Stderr: &errb})
		if err == nil && res.ExitCode != 0 {
			err = fmt.Errorf("%s: exit %d: %s", q, res.ExitCode, errb.String())
		}
		return strings.TrimSpace(out.String()), err
	}
	must := func(id, q string) string {
		t.Helper()
		out, err := sql(id, q)
		if err != nil {
			t.Fatal(err)
		}
		return out
	}
	if _, _, err := d.exec(ctx, src, nil, nil, nil, "database", "create", "--name", "app"); err != nil {
		t.Fatal(err)
	}
	must(src, "CREATE TABLE items (id int PRIMARY KEY, note varchar(40))")
	if engine != "postgres" {
		must(src, "CREATE USER "+appUser+"@'%' IDENTIFIED BY 'app-Pa55word'")
		must(src, "GRANT ALL ON app.* TO "+appUser+"@'%'")
	}
	must(src, "INSERT INTO items VALUES (1, 'before the base'), (2, 'before the base')")

	t.Log("base backup")
	baseEnc := backupcrypt.Encryption{Mode: "cp", KeyID: "01hzye2ebase0000000000001", Key: strings.Repeat("cd", 32)}
	res, err := d.PITRBase(ctx, PITRBasePayload{Instance: src, Engine: engine, Encryption: baseEnc, Destination: Location{Kind: "presigned_url", URL: store.url("base")}}, stream())
	if err != nil {
		t.Fatal(err)
	}
	base := res.(PITRBaseResult)
	t.Logf("base: %d bytes, started %s, start_wal %q start_binlog %q", base.SizeBytes, base.StartedAt, base.StartWAL, base.StartBinlog)
	baseStart, _ := time.Parse(time.RFC3339, base.StartedAt)

	must(src, "INSERT INTO items VALUES (3, 'after the base'), (4, 'after the base')")
	time.Sleep(2 * time.Second)
	target := time.Now().UTC()
	time.Sleep(2 * time.Second)
	must(src, "DROP TABLE items")
	must(src, "CREATE TABLE later (v int)")
	if engine == "postgres" {
		must(src, "SELECT pg_switch_wal()")
	} else if err := ship.rotate(ctx, src, d.pitrConfigs()[src], true); err != nil {
		t.Fatal(err)
	}
	t.Logf("target time %s; shipping the spool", target.Format(time.RFC3339Nano))
	deadline := time.Now().Add(2 * time.Minute)
	for {
		ship.st(src).retryAt = time.Time{}
		ship.RunOnce(ctx)
		cp.mu.Lock()
		var last time.Time
		for _, s := range cp.shipped {
			if s.EndTime.After(last) {
				last = s.EndTime
			}
		}
		cp.mu.Unlock()
		if !last.Before(target.Add(time.Second)) {
			break
		}
		if time.Now().After(deadline) {
			t.Fatalf("nothing past the target shipped (last segment ends %s): %s", last, ship.st(src).lastErr)
		}
		time.Sleep(2 * time.Second)
	}
	if files, _ := d.pending(d.pitrConfigs()[src]); len(files) != 0 {
		t.Logf("%d files still in the spool", len(files))
	}

	// What the control plane picks: the segments from the base on, up to the first one ending at or after T.
	cp.mu.Lock()
	var segs []shippedSegment
	for _, s := range cp.shipped {
		segs = append(segs, s)
	}
	keys := cp.keys
	cp.mu.Unlock()
	sort.Slice(segs, func(i, j int) bool { return segs[i].Name < segs[j].Name })
	var chosen []PITRSegment
	for _, s := range segs {
		if strings.HasSuffix(s.Name, ".history") || !s.EndTime.Before(baseStart.Add(-time.Second)) && (engine != "postgres" || s.Name >= base.StartWAL) {
			chosen = append(chosen, PITRSegment{Name: s.Name, PITRObject: PITRObject{URL: store.url(s.ID), SHA256: s.SHA256, PlaintextSHA256: s.PlaintextSHA256,
				SizeBytes: s.SizeBytes, PlaintextBytes: s.PlaintextBytes, Encryption: keys[s.ID]}})
			if !s.EndTime.Before(target) && !strings.HasSuffix(s.Name, ".backup") {
				break
			}
		}
	}
	t.Logf("restoring to %s from the base and %d of %d segments", target.Format(time.RFC3339Nano), len(chosen), len(segs))
	out, err := d.PITRRestore(ctx, PITRRestorePayload{Restore: "01hzye2erestore00000000001", Instance: spec(dst, "01hzye2evol000000000000002", false), Password: password,
		Base: PITRObject{URL: store.url("base"), SHA256: base.SHA256, PlaintextSHA256: base.PlaintextSHA256, SizeBytes: base.SizeBytes, PlaintextBytes: base.UncompressedBytes,
			Encryption: baseEnc}, Segments: chosen, TargetTime: target.Format(time.RFC3339Nano), Databases: []string{"app"}}, stream())
	if err != nil {
		t.Fatal(err)
	}
	r := out.(PITRRestoreResult)
	t.Logf("restored: %+v", r)
	if got := must(dst, "SELECT count(*) FROM items"); got != "4" {
		t.Fatalf("rows after the restore: %s, want 4", got)
	}
	if _, err := sql(dst, "SELECT count(*) FROM later"); err == nil {
		t.Fatal("a table created after T is there")
	}
	counts := r.TableCounts["app"]
	if counts == nil {
		t.Fatalf("no row counts: %+v", r)
	}
	asApp = engine != "postgres"
	if _, err := sql(dst, "INSERT INTO items VALUES (5, 'read-only?')"); err == nil {
		t.Fatal("the restored copy accepted a write before it was promoted")
	}
	if _, err := d.PITRPromote(ctx, PITRPromotePayload{Instance: dst, Engine: engine}, stream()); err != nil {
		t.Fatal(err)
	}
	if engine == "postgres" {
		// Default read-only applies to new sessions: psql opens one per statement.
		time.Sleep(time.Second)
	}
	must(dst, "INSERT INTO items VALUES (5, 'writable')")
	asApp = false
	t.Logf("PASS: %s %s recovered to T (4 rows, the DROP TABLE undone), read-only until promoted", engine, version)
}
