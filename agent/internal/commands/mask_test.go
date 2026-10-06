package commands

import (
	"context"
	"encoding/json"
	"errors"
	"strings"
	"testing"
)

type maskedPayload struct {
	Env  map[string]string `json:"env"`
	Mask []string          `json:"mask"`
}

func (p maskedPayload) Secrets() []string {
	var out []string
	for _, k := range p.Mask {
		out = append(out, p.Env[k])
	}
	return out
}

func TestDispatcherMasksPayloadSecrets(t *testing.T) {
	reg := NewRegistry()
	reg.Register("t.leak", Typed(func(ctx context.Context, p maskedPayload, s Stream) (any, error) {
		pw := p.Env["DB_PASSWORD"]
		// Split across writes, and on both streams.
		s.Stdout().Write([]byte("connecting with " + pw[:3]))
		s.Stdout().Write([]byte(pw[3:] + " as " + p.Env["DB_USER"] + "\n"))
		s.Stderr().Write([]byte("warning: " + pw))
		return map[string]string{"dsn": "pg://u:" + pw + "@db"}, errors.New("auth failed for " + pw)
	}))
	c := run(t, reg, Envelope{ID: "m1", Type: "t.leak", Payload: json.RawMessage(`{"env":{"DB_PASSWORD":"hunter2-secret","DB_USER":"falak-app"},"mask":["DB_PASSWORD"]}`)})
	f := finished(t, c, "m1")
	b, _ := json.Marshal(f.Result)
	if all := c.Output("") + f.Error + string(b); strings.Contains(all, "hunter2-secret") {
		t.Fatalf("secret leaked: %s", all)
	}
	if got := c.Output("stdout"); got != "connecting with •••• as falak-app\n" {
		t.Fatalf("stdout %q", got)
	}
	if got := c.Output("stderr"); got != "warning: ••••" {
		t.Fatalf("stderr %q", got)
	}
	if f.Error != "auth failed for ••••" || !strings.Contains(string(b), "pg://u:••••@db") {
		t.Fatalf("error %q result %s", f.Error, b)
	}
}
