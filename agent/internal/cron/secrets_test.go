package cron

import (
	"context"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

func TestJobSecretsStayOffDiskAndWaitAfterReboot(t *testing.T) {
	dir := t.TempDir()
	opts := Options{StateDir: filepath.Join(dir, "state"), SecretsPath: filepath.Join(dir, "run", "cron-secrets.json"), Runner: &runnertest.Fake{},
		Clock: &fakeClock{now: time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)}}
	jobs := []Job{
		{Name: "shop.backup", Schedule: "0 * * * *", Command: "php artisan backup", Site: "shop", Env: map[string]string{"APP_ENV": "production", "DB_PASSWORD": "pw-123456"}, Mask: []string{"DB_PASSWORD"}},
		{Name: "plain", Schedule: "0 * * * *", Command: "true", Env: map[string]string{"A": "1"}},
	}
	s1 := New(opts)
	if _, err := s1.Apply(jobs); err != nil {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(filepath.Join(dir, "state", "cron.json")); strings.Contains(string(b), "pw-123456") || !strings.Contains(string(b), "DB_PASSWORD") {
		t.Fatalf("cron.json: %s", b)
	}

	// Restart with the tmpfs intact: everything comes back.
	s2 := New(opts)
	ctx, cancel := context.WithCancel(context.Background())
	if err := s2.Start(ctx); err != nil {
		t.Fatal(err)
	}
	cancel()
	s2.Wait()
	if len(s2.WaitingSites()) != 0 || len(s2.entries) != 2 || s2.entries["shop.backup"].job.Env["DB_PASSWORD"] != "pw-123456" {
		t.Fatalf("restore: waiting %v entries %d", s2.WaitingSites(), len(s2.entries))
	}

	// Reboot: the job with secrets waits, the other runs; the state file still knows the waiting job.
	os.RemoveAll(filepath.Join(dir, "run"))
	s3 := New(opts)
	ctx, cancel = context.WithCancel(context.Background())
	if err := s3.Start(ctx); err != nil {
		t.Fatal(err)
	}
	cancel()
	s3.Wait()
	if !slices.Equal(s3.WaitingSites(), []string{"shop"}) || len(s3.entries) != 1 {
		t.Fatalf("waiting %v entries %d", s3.WaitingSites(), len(s3.entries))
	}
	if b, _ := os.ReadFile(filepath.Join(dir, "state", "cron.json")); !strings.Contains(string(b), "shop.backup") {
		t.Fatal("the waiting job was dropped from cron.json")
	}
	// The control plane's cron.apply brings it back.
	if _, err := s3.Apply(jobs); err != nil {
		t.Fatal(err)
	}
	if len(s3.WaitingSites()) != 0 || len(s3.entries) != 2 {
		t.Fatalf("after apply: waiting %v entries %d", s3.WaitingSites(), len(s3.entries))
	}
}
