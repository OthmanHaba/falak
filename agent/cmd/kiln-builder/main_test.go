package main

import (
	"bytes"
	"encoding/json"
	"strings"
	"testing"
)

func TestDetectCommand(t *testing.T) {
	var out, errb bytes.Buffer
	if code := run([]string{"detect", "../../internal/builder/testdata/fixtures/next"}, nil, &out, &errb); code != 0 {
		t.Fatalf("code %d: %s", code, errb.String())
	}
	var plan map[string]any
	if err := json.Unmarshal(out.Bytes(), &plan); err != nil || plan["framework"] != "next" {
		t.Fatalf("plan = %s", out.String())
	}
	out.Reset()
	if code := run([]string{"detect", "--dockerfile", "../../internal/builder/testdata/fixtures/static"}, nil, &out, &errb); code != 0 || !strings.Contains(out.String(), "FROM caddy") {
		t.Fatalf("dockerfile = %s", out.String())
	}
}

func TestRunRejectsBadJSON(t *testing.T) {
	var out, errb bytes.Buffer
	if code := run([]string{"run"}, strings.NewReader("{"), &out, &errb); code != 2 {
		t.Fatalf("code = %d", code)
	}
	if code := run([]string{"bogus"}, nil, &out, &errb); code != 2 {
		t.Fatalf("code = %d", code)
	}
	if code := run([]string{"serve"}, nil, &out, &errb); code != 2 {
		t.Fatalf("serve without url/token code = %d", code)
	}
}

func TestRunInvalidJobEmitsEvents(t *testing.T) {
	var out, errb bytes.Buffer
	code := run([]string{"run", "--work-dir", t.TempDir()}, strings.NewReader(`{"id":"b1","mode":"native"}`), &out, &errb)
	lines := strings.Split(strings.TrimSpace(out.String()), "\n")
	if code != 1 || len(lines) < 2 || !strings.Contains(lines[0], `"kind":"started"`) || !strings.Contains(lines[len(lines)-1], `"kind":"finished"`) {
		t.Fatalf("code=%d out=%s", code, out.String())
	}
}
