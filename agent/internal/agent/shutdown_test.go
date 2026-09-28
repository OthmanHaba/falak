package agent

import (
	"sync"
	"testing"
	"time"
)

func TestGracefulStopStopsPollingBeforeDrainingAndCancelling(t *testing.T) {
	var mu sync.Mutex
	var steps []string
	rec := func(s string) func() {
		return func() { mu.Lock(); steps = append(steps, s); mu.Unlock() }
	}
	gracefulStop(rec("stop polling"), rec("poller stopped"), rec("commands drained"), time.Second, rec("cancel rest"))

	want := []string{"stop polling", "poller stopped", "commands drained", "cancel rest"}
	if len(steps) != len(want) {
		t.Fatalf("steps = %v, want %v", steps, want)
	}
	for i := range want {
		if steps[i] != want[i] {
			t.Fatalf("steps = %v, want %v", steps, want)
		}
	}
}

func TestGracefulStopCancelsCommandsThatOutliveTheDrain(t *testing.T) {
	block := make(chan struct{})
	defer close(block)
	cancelled := make(chan struct{})
	start := time.Now()
	gracefulStop(func() {}, func() {}, func() { <-block }, 50*time.Millisecond, func() { close(cancelled) })
	select {
	case <-cancelled:
	default:
		t.Fatal("remaining work was not cancelled after the drain")
	}
	if d := time.Since(start); d > 2*time.Second {
		t.Fatalf("drain took %s, want about 50ms", d)
	}
}
