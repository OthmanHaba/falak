package supervisor

import (
	"context"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"
)

func TestProgramSecretsStayOffDiskAndWaitAfterReboot(t *testing.T) {
	dir := t.TempDir()
	opts := Options{StateDir: filepath.Join(dir, "state"), SecretsPath: filepath.Join(dir, "run", "proc-secrets.json"), LogDir: filepath.Join(dir, "log")}
	programs := []Program{
		{Name: "shop.worker", Command: sh("exec sleep 30"), Site: "shop", Env: map[string]string{"APP_ENV": "production", "DB_PASSWORD": "pw-123456"}, Mask: []string{"DB_PASSWORD"}},
		{Name: "plain", Command: sh("exec sleep 30"), Site: "blog"},
	}
	s1 := New(opts)
	if _, err := s1.Apply(context.Background(), programs); err != nil {
		t.Fatal(err)
	}
	s1.Shutdown()
	if b, _ := os.ReadFile(filepath.Join(dir, "state", "proc.json")); strings.Contains(string(b), "pw-123456") || !strings.Contains(string(b), "DB_PASSWORD") {
		t.Fatalf("proc.json: %s", b)
	}
	if fi, err := os.Stat(opts.SecretsPath); err != nil || fi.Mode().Perm() != 0o600 {
		t.Fatalf("tmpfs secrets: %v %v", fi, err)
	}

	// Restart with the tmpfs intact: both run, and the control plane's next apply changes nothing.
	s2 := New(opts)
	if err := s2.Start(context.Background()); err != nil {
		t.Fatal(err)
	}
	eventually(t, 2*time.Second, func() bool { return len(s2.Status(nil)) == 2 }, "both restored")
	if res, _ := s2.Apply(context.Background(), programs); res.Changed {
		t.Fatalf("restored with other env: %+v", res)
	}
	s2.Shutdown()

	// Reboot: the program with secrets waits instead of starting without them.
	os.RemoveAll(filepath.Join(dir, "run"))
	s3 := New(opts)
	t.Cleanup(s3.Shutdown)
	if err := s3.Start(context.Background()); err != nil {
		t.Fatal(err)
	}
	if st := s3.Status(nil); len(st) != 1 || st[0].Name != "plain" {
		t.Fatalf("status %+v", st)
	}
	if !slices.Equal(s3.WaitingSites(), []string{"shop"}) {
		t.Fatalf("waiting %v", s3.WaitingSites())
	}
	if b, _ := os.ReadFile(filepath.Join(dir, "state", "proc.json")); !strings.Contains(string(b), "shop.worker") {
		t.Fatal("the waiting program was dropped from proc.json")
	}
	if _, err := s3.Apply(context.Background(), programs); err != nil {
		t.Fatal(err)
	}
	eventually(t, 2*time.Second, func() bool { return len(s3.Status(nil)) == 2 && len(s3.WaitingSites()) == 0 }, "restored by proc.apply")
}
