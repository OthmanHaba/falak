package db

import (
	"context"
	"io"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

// A read error while streaming cancels the consumer's exec before it can see the end of its input, and a truncated
// file never passes the verification pass run before any restore.
func TestDumpReaderCancelsOnStreamErrors(t *testing.T) {
	h := newHarness(t)
	file, _ := sealedFile(t, h, strings.Repeat("row\n", 100000))
	b, _ := os.ReadFile(h.path(file))
	os.WriteFile(h.path(file), b[:len(b)/2], 0o600)
	in, err := openDump(h.path(file), testEnc, "")
	if err != nil {
		t.Fatal(err)
	}
	defer in.Close()
	cancelled := false
	in.cancel = func() { cancelled = true }
	if _, err := io.Copy(io.Discard, in); err == nil || !cancelled || in.check(nil) == nil {
		t.Fatalf("err %v cancelled %v", err, cancelled)
	}
	if err := verifyDump(h.path(file), testEnc, ""); err == nil {
		t.Fatal("a truncated backup verified")
	}
}

// The agent gives up on a check query that outlives CheckQueryWait, whatever the engine does.
func TestCheckQueryWallClock(t *testing.T) {
	h := newHarness(t)
	h.db.d.CheckQueryWait = 20 * time.Millisecond
	h.run.OnFunc("docker exec -i c falak-db query", func(runnertest.Call) (runner.Result, error) {
		time.Sleep(100 * time.Millisecond)
		return runner.Result{Stdout: []byte(`{"rows":1}`)}, nil
	})
	if _, err := h.db.checkQuery(context.Background(), "c", "app", "SELECT 1"); err == nil || !strings.Contains(err.Error(), "did not finish") {
		t.Fatalf("err %v", err)
	}
}
