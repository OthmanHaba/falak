package commands

import (
	"fmt"
	"testing"
)

func TestEventStreamFraming(t *testing.T) {
	var c Collector
	s := NewEventStream("b1", &c, nil)
	s.Started()
	fmt.Fprint(s.Stdout(), "hello\n")
	s.Progress(0.5)
	fmt.Fprint(s.Stderr(), "warn\n")
	s.Finished(0, map[string]any{"ok": true}, "")
	evs := c.Snapshot()
	kinds := []string{KindStarted, KindOutput, KindProgress, KindOutput, KindFinished}
	if len(evs) != len(kinds) {
		t.Fatalf("got %d events: %+v", len(evs), evs)
	}
	for i, ev := range evs {
		if ev.Kind != kinds[i] || ev.Seq != int64(i) || ev.CommandID != "b1" {
			t.Fatalf("event %d = %+v", i, ev)
		}
	}
	if evs[4].ExitCode == nil || *evs[4].ExitCode != 0 || evs[4].Result == nil {
		t.Fatalf("finished = %+v", evs[4])
	}
	if c.Output("stdout") != "hello\n" || c.Output("stderr") != "warn\n" {
		t.Fatalf("output mismatch")
	}
}
