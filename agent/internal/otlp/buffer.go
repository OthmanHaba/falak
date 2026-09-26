package otlp

import (
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"time"
)

// DefaultBufferMax is the default disk buffer bound (telemetry contract: 64 MB).
const DefaultBufferMax int64 = 64 << 20

// DiskBuffer stores encoded export requests as segment files, oldest first. When the bound is
// exceeded the oldest segments are dropped.
type DiskBuffer struct {
	dir string

	mu    sync.Mutex
	max   int64
	size  int64
	seq   uint64
	segs  []segment // sorted oldest → newest
	drops uint64
}

type segment struct {
	name string
	sig  Signal
	size int64
}

// OpenDiskBuffer opens (creating) dir and indexes existing segments.
func OpenDiskBuffer(dir string, max int64) (*DiskBuffer, error) {
	if max <= 0 {
		max = DefaultBufferMax
	}
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return nil, err
	}
	b := &DiskBuffer{dir: dir, max: max}
	ents, err := os.ReadDir(dir)
	if err != nil {
		return nil, err
	}
	for _, e := range ents {
		sig, ok := parseSegName(e.Name())
		if !ok {
			if strings.HasSuffix(e.Name(), ".tmp") {
				_ = os.Remove(filepath.Join(dir, e.Name()))
			}
			continue
		}
		info, err := e.Info()
		if err != nil {
			continue
		}
		b.segs = append(b.segs, segment{e.Name(), sig, info.Size()})
		b.size += info.Size()
	}
	sort.Slice(b.segs, func(i, j int) bool { return b.segs[i].name < b.segs[j].name })
	b.evictLocked(0)
	return b, nil
}

func parseSegName(n string) (Signal, bool) {
	if !strings.HasSuffix(n, ".pb") {
		return "", false
	}
	parts := strings.Split(strings.TrimSuffix(n, ".pb"), "-")
	if len(parts) != 3 {
		return "", false
	}
	switch s := Signal(parts[2]); s {
	case Traces, Logs, Metrics:
		return s, true
	}
	return "", false
}

// SetMax changes the bound (evicting immediately if needed).
func (b *DiskBuffer) SetMax(max int64) {
	if max <= 0 {
		max = DefaultBufferMax
	}
	b.mu.Lock()
	b.max = max
	b.evictLocked(0)
	b.mu.Unlock()
}

// Write appends a segment. Data larger than the whole bound is dropped.
func (b *DiskBuffer) Write(sig Signal, data []byte) error {
	b.mu.Lock()
	defer b.mu.Unlock()
	n := int64(len(data))
	if n > b.max {
		b.drops++
		return fmt.Errorf("otlp buffer: segment of %d bytes exceeds bound %d", n, b.max)
	}
	b.evictLocked(n)
	b.seq++
	name := fmt.Sprintf("%020d-%06d-%s.pb", time.Now().UnixNano(), b.seq%1000000, sig)
	path := filepath.Join(b.dir, name)
	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, data, 0o600); err != nil {
		return err
	}
	if err := os.Rename(tmp, path); err != nil {
		return err
	}
	b.segs = append(b.segs, segment{name, sig, n})
	b.size += n
	return nil
}

// evictLocked drops oldest segments until size+incoming fits.
func (b *DiskBuffer) evictLocked(incoming int64) {
	for len(b.segs) > 0 && b.size+incoming > b.max {
		s := b.segs[0]
		_ = os.Remove(filepath.Join(b.dir, s.name))
		b.size -= s.size
		b.segs = b.segs[1:]
		b.drops++
	}
}

// Oldest returns the oldest segment (ok=false when empty).
func (b *DiskBuffer) Oldest() (name string, sig Signal, data []byte, ok bool) {
	for {
		b.mu.Lock()
		if len(b.segs) == 0 {
			b.mu.Unlock()
			return "", "", nil, false
		}
		s := b.segs[0]
		b.mu.Unlock()
		data, err := os.ReadFile(filepath.Join(b.dir, s.name))
		if errors.Is(err, fs.ErrNotExist) || (err == nil && len(data) == 0 && s.size != 0) {
			b.Remove(s.name)
			continue
		}
		if err != nil {
			return "", "", nil, false
		}
		return s.name, s.sig, data, true
	}
}

// Remove deletes a segment after successful replay.
func (b *DiskBuffer) Remove(name string) {
	b.mu.Lock()
	defer b.mu.Unlock()
	for i, s := range b.segs {
		if s.name == name {
			_ = os.Remove(filepath.Join(b.dir, name))
			b.size -= s.size
			b.segs = append(b.segs[:i], b.segs[i+1:]...)
			return
		}
	}
}

// Stats returns segment count, total bytes and number of dropped segments.
func (b *DiskBuffer) Stats() (segments int, bytes int64, dropped uint64) {
	b.mu.Lock()
	defer b.mu.Unlock()
	return len(b.segs), b.size, b.drops
}
