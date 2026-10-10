// Package resources queues what the agent saw happen to services' resources — OOM kills and restarts — until a
// heartbeat delivers them (heartbeat `service_events`). Containers (docker events), slices (cgroup memory.events)
// and supervised programs (restart counters) feed it.
package resources

import (
	"sync"
	"time"
)

// Event kinds.
const (
	KindOOMKill = "oom_kill"
	KindRestart = "restart"
)

// Event sources.
const (
	SourceContainer = "container"
	SourceSlice     = "slice"
	SourceProgram   = "program"
)

// Event mirrors one heartbeat `service_events` entry. The control plane maps it to its service: a container by its
// labels (site, compose project/service, database instance), a slice by its key, a program by its name.
type Event struct {
	Kind   string `json:"kind"`
	Source string `json:"source"`
	// Name is the container, slice key or program name.
	Name     string `json:"name"`
	Site     string `json:"site,omitempty"`
	Project  string `json:"project,omitempty"`
	Service  string `json:"service,omitempty"`
	Instance string `json:"instance,omitempty"`
	// Count is how many kills or restarts this event stands for (since the previous one of the same service).
	Count int       `json:"count"`
	At    time.Time `json:"at"`
}

// MaxPending bounds the queue while the control plane is unreachable (oldest dropped first); PerBeat bounds one beat.
const (
	MaxPending = 500
	PerBeat    = 100
)

// Queue holds events until a heartbeat delivered them.
type Queue struct {
	mu     sync.Mutex
	events []Event
	seq    uint64 // events ever dropped from the front (delivered or capped)
}

// Add queues an event (At defaults to now); an event without a count is ignored.
func (q *Queue) Add(e Event) {
	if e.Count <= 0 {
		return
	}
	if e.At.IsZero() {
		e.At = time.Now().UTC()
	}
	q.mu.Lock()
	defer q.mu.Unlock()
	q.events = append(q.events, e)
	if over := len(q.events) - MaxPending; over > 0 {
		q.events = append([]Event(nil), q.events[over:]...)
		q.seq += uint64(over)
	}
}

// Pending returns up to PerBeat events and the func that drops them once delivered (nil events: nothing pending).
// Events queued in between stay.
func (q *Queue) Pending() ([]Event, func()) {
	q.mu.Lock()
	defer q.mu.Unlock()
	n := min(len(q.events), PerBeat)
	if n == 0 {
		return nil, func() {}
	}
	out := append([]Event(nil), q.events[:n]...)
	start := q.seq
	return out, func() {
		q.mu.Lock()
		defer q.mu.Unlock()
		// The cap may have dropped some of the batch meanwhile: drop only what is still queued of it.
		end := start + uint64(n)
		if end <= q.seq {
			return
		}
		drop := int(end - q.seq)
		q.events = append([]Event(nil), q.events[drop:]...)
		q.seq = end
	}
}

// Counter turns cumulative counters (restart counts, memory.events oom_kill) into deltas. A counter that went down
// was reset (a new container, a restarted program): its whole value is new.
type Counter struct {
	mu   sync.Mutex
	last map[string]int
}

// Delta records the counter's value and returns the increase since the last call; the first sighting is a baseline.
func (c *Counter) Delta(key string, value int) int {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.last == nil {
		c.last = map[string]int{}
	}
	prev, seen := c.last[key]
	c.last[key] = value
	switch {
	case !seen:
		return 0
	case value < prev:
		return value
	default:
		return value - prev
	}
}

// Forget drops counters not in keep (services gone).
func (c *Counter) Forget(keep map[string]bool) {
	c.mu.Lock()
	defer c.mu.Unlock()
	for k := range c.last {
		if !keep[k] {
			delete(c.last, k)
		}
	}
}
