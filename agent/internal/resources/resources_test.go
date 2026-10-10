package resources

import (
	"testing"
)

func TestQueueDeliversInBatchesAndKeepsNewEvents(t *testing.T) {
	var q Queue
	q.Add(Event{Kind: KindOOMKill, Name: "a"}) // no count: ignored
	if ev, _ := q.Pending(); ev != nil {
		t.Fatalf("%v", ev)
	}
	for i := 0; i < PerBeat+5; i++ {
		q.Add(Event{Kind: KindRestart, Source: SourceContainer, Name: "c", Count: i + 1})
	}
	ev, ack := q.Pending()
	if len(ev) != PerBeat || ev[0].At.IsZero() {
		t.Fatalf("%d %v", len(ev), ev[0])
	}
	q.Add(Event{Kind: KindOOMKill, Source: SourceSlice, Name: "site_shop", Count: 1})
	ack()
	ev, ack = q.Pending()
	if len(ev) != 6 || ev[5].Name != "site_shop" {
		t.Fatalf("%v", ev)
	}
	ack()
	if ev, _ := q.Pending(); ev != nil {
		t.Fatalf("%v", ev)
	}
}

func TestQueueDropsOldestOverCap(t *testing.T) {
	var q Queue
	for i := 0; i < MaxPending+10; i++ {
		q.Add(Event{Kind: KindRestart, Name: "c", Count: i + 1})
	}
	ev, ack := q.Pending()
	if ev[0].Count != 11 {
		t.Fatalf("oldest kept: %d", ev[0].Count)
	}
	// The cap drops part of an undelivered batch: its ack drops only the rest.
	for i := 0; i < 40; i++ {
		q.Add(Event{Kind: KindRestart, Name: "new", Count: 1})
	}
	ack()
	ev, _ = q.Pending()
	if ev[0].Count != 111 {
		t.Fatalf("after ack: %d", ev[0].Count)
	}
}

func TestCounterDeltas(t *testing.T) {
	var c Counter
	for _, tc := range []struct{ value, want int }{{3, 0}, {3, 0}, {5, 2}, {1, 1}, {4, 3}} {
		if got := c.Delta("k", tc.value); got != tc.want {
			t.Fatalf("value %d: delta %d, want %d", tc.value, got, tc.want)
		}
	}
	c.Forget(map[string]bool{})
	if c.Delta("k", 9) != 0 {
		t.Fatal("forgotten counter is a new baseline")
	}
}
