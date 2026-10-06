package supervisor

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestProgramLogsMaskSecrets(t *testing.T) {
	s, sink, dir := newSup(t)
	_, err := s.Apply(context.Background(), []Program{{Name: "worker", Command: sh(`echo "connecting with $DB_PASSWORD"; echo "err $DB_PASSWORD" >&2; exec sleep 30`),
		Env: map[string]string{"DB_PASSWORD": "hunter2-secret"}, Mask: []string{"DB_PASSWORD"}}})
	if err != nil {
		t.Fatal(err)
	}
	eventually(t, 3*time.Second, func() bool {
		b, _ := os.ReadFile(filepath.Join(dir, "log", "worker.out.log"))
		e, _ := os.ReadFile(filepath.Join(dir, "log", "worker.err.log"))
		return strings.Contains(string(b), "connecting with ••••") && strings.Contains(string(e), "err ••••")
	}, "masked log files")
	eventually(t, time.Second, func() bool { return strings.Contains(strings.Join(sink.bodies(), ","), "connecting with ••••") }, "masked otlp")
	for _, f := range []string{"worker.out.log", "worker.err.log"} {
		if b, _ := os.ReadFile(filepath.Join(dir, "log", f)); strings.Contains(string(b), "hunter2") {
			t.Fatalf("%s leaks the secret", f)
		}
	}
	if strings.Contains(strings.Join(sink.bodies(), ","), "hunter2") {
		t.Fatal("otlp leaks the secret")
	}
}
