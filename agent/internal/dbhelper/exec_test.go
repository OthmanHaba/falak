package dbhelper

import (
	"bytes"
	"context"
	"strings"
	"testing"
)

func TestExecRunner(t *testing.T) {
	r := ExecRunner{}
	out, err := output(context.Background(), r, Cmd{Name: "sh", Args: []string{"-c", `printf '%s' "$X"`}, Env: []string{"X=from-env"}})
	if err != nil || out != "from-env" {
		t.Fatalf("output %q, %v", out, err)
	}
	err = r.Run(context.Background(), Cmd{Name: "sh", Args: []string{"-c", "echo boom >&2; exit 3"}})
	if err == nil || !strings.Contains(err.Error(), "boom") {
		t.Errorf("error without the tool's message: %v", err)
	}
}

func TestPipe(t *testing.T) {
	r := ExecRunner{}
	var out bytes.Buffer
	err := pipe(context.Background(), r,
		Cmd{Name: "sh", Args: []string{"-c", "echo one; echo two"}},
		Cmd{Name: "sh", Args: []string{"-c", "tr a-z A-Z"}, Stdout: &out})
	if err != nil || out.String() != "ONE\nTWO\n" {
		t.Errorf("pipe: %q, %v", out.String(), err)
	}

	// The consumer's failure is the one reported, not the producer's broken pipe.
	err = pipe(context.Background(), r,
		Cmd{Name: "sh", Args: []string{"-c", "yes | head -c 1000000"}},
		Cmd{Name: "sh", Args: []string{"-c", "echo consumer failed >&2; exit 1"}})
	if err == nil || !strings.Contains(err.Error(), "consumer failed") {
		t.Errorf("pipe error: %v", err)
	}
}

func TestTailBuffer(t *testing.T) {
	tb := &tailBuffer{max: 4}
	for _, s := range []string{"ab", "cdef", "ghijklmnop"} {
		tb.Write([]byte(s))
	}
	if got := string(tb.bytes()); got != "mnop" {
		t.Errorf("tail %q", got)
	}
}
