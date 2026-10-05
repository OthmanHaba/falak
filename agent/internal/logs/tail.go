// Package logs ships log files and Docker container logs into the OTLP relay (replacing
// promtail/alloy). Files are polled, so rotation (rename + recreate) and truncation are both handled
// without inotify; offsets survive agent restarts.
package logs

import (
	"bytes"
	"context"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/obs"
)

// Source is one configured log source.
type Source struct {
	Path    string `json:"path"` // absolute host path or glob
	Service string `json:"service,omitempty"`
	Site    string `json:"site,omitempty"`
	Format  string `json:"format,omitempty"` // plain | json
	// Kind is the falak.log.kind of the records: "app" (default for site sources) or "access" (edge HTTP
	// access log in Caddy's JSON format, flattened into http.* / url.* / client.* attributes).
	Kind string `json:"kind,omitempty"`
	// Multiline merges continuation lines into the record they belong to. "laravel": a record starts
	// with "[YYYY-MM-DD" (Monolog's line format), so stack traces become part of the error record.
	Multiline string `json:"multiline,omitempty"`
	// SiteFromFile takes the site slug from the file name (<slug>.log); the edge access logs use it.
	SiteFromFile bool `json:"-"`
}

// AccessLogDir holds the edge's per-site HTTP access logs (<slug>.log, Caddy JSON). falak-edge writes
// them (edge.caddy.apply sites[].access_log) and the telemetry service always tails them.
const AccessLogDir = "/var/log/falak/access"

// AccessSource is the built-in source for AccessLogDir.
func AccessSource() Source {
	return Source{Path: AccessLogDir + "/*.log", Kind: "access", Format: "json", SiteFromFile: true}
}

// MaxRecord bounds a merged multi-line record (a long stack trace); beyond it a new record starts.
const MaxRecord = 256 << 10

// multilineStart matches the first line of a record per Multiline mode.
var multilineStart = map[string]*regexp.Regexp{
	"laravel": regexp.MustCompile(`^\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}`),
}

var slugRE = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{0,62}$`)

// MaxLine bounds a single line; longer lines are split.
const MaxLine = 64 << 10

// readChunk bounds bytes read per file per poll so one busy file cannot starve the others.
const readChunk = 4 << 20

type fileState struct {
	src     Source
	f       *os.File
	ino     uint64
	offset  int64
	partial []byte
	head    []byte // first ≤ headLen bytes; a change means the file was truncated and rewritten
	// pending is a multi-line record still collecting continuation lines; pendingN is the number of
	// file bytes it spans (excluded from the saved offset so a restart re-reads it).
	pending  []byte
	pendingN int64
}

const headLen = 64

type savedOffset struct {
	Ino    uint64 `json:"ino"`
	Offset int64  `json:"offset"`
	Head   []byte `json:"head,omitempty"`
}

// Tailer follows files matching the configured sources.
type Tailer struct {
	fs        hostfs.FS
	statePath string // real path of the offsets file
	sink      obs.Sink
	log       *slog.Logger
	interval  time.Duration

	mu      sync.Mutex
	sources []Source
	fresh   map[string]bool // globs not yet polled: files existing at first sight start at EOF
	files   map[string]*fileState
	saved   map[string]savedOffset
	dirty   bool
}

// NewTailer creates a tailer; offsets persist in <stateDir>/log-offsets.json (stateDir is a host path).
func NewTailer(fs hostfs.FS, stateDir string, sink obs.Sink, log *slog.Logger) *Tailer {
	if log == nil {
		log = slog.Default()
	}
	t := &Tailer{fs: fs, statePath: fs.P(filepath.Join(stateDir, "log-offsets.json")), sink: sink, log: log,
		interval: time.Second, fresh: map[string]bool{}, files: map[string]*fileState{}, saved: map[string]savedOffset{}}
	if b, err := os.ReadFile(t.statePath); err == nil {
		_ = json.Unmarshal(b, &t.saved)
	}
	return t
}

// SetInterval changes the poll interval (tests).
func (t *Tailer) SetInterval(d time.Duration) { t.interval = d }

// SetSources replaces the configured sources; files no longer matched are closed.
func (t *Tailer) SetSources(srcs []Source) {
	t.mu.Lock()
	defer t.mu.Unlock()
	known := map[string]bool{}
	for _, s := range t.sources {
		known[s.Path] = true
	}
	t.sources = append([]Source(nil), srcs...)
	for _, s := range srcs {
		if !known[s.Path] {
			t.fresh[s.Path] = true
		}
	}
}

// Run polls until ctx is done.
func (t *Tailer) Run(ctx context.Context) {
	tick := time.NewTicker(t.interval)
	defer tick.Stop()
	for {
		t.Poll()
		select {
		case <-ctx.Done():
			t.mu.Lock()
			for _, fsx := range t.files {
				fsx.f.Close()
			}
			t.files = map[string]*fileState{}
			t.saveLocked()
			t.mu.Unlock()
			return
		case <-tick.C:
		}
	}
}

func inode(fi os.FileInfo) uint64 {
	if st, ok := fi.Sys().(*syscall.Stat_t); ok {
		return uint64(st.Ino)
	}
	return 0
}

// Poll performs one pass over all sources.
func (t *Tailer) Poll() {
	t.mu.Lock()
	defer t.mu.Unlock()
	seen := map[string]bool{}
	for _, src := range t.sources {
		matches, err := filepath.Glob(t.fs.P(src.Path))
		if err != nil {
			continue
		}
		first := t.fresh[src.Path]
		delete(t.fresh, src.Path)
		sort.Strings(matches)
		for _, real := range matches {
			if seen[real] {
				continue
			}
			seen[real] = true
			t.pollFile(real, src, first)
		}
	}
	for real, st := range t.files {
		if !seen[real] {
			// Path vanished (rotated away, not yet recreated): drain what is left, then close.
			t.readAvailable(real, st)
			t.flushPartial(real, st)
			t.flushPending(real, st)
			st.f.Close()
			delete(t.files, real)
		}
	}
	if t.dirty {
		t.saveLocked()
	}
}

func (t *Tailer) pollFile(real string, src Source, first bool) {
	fi, err := os.Stat(real)
	if err != nil || !fi.Mode().IsRegular() {
		return
	}
	ino := inode(fi)
	st := t.files[real]
	if st != nil && st.ino != ino {
		// Rotated: finish the old file first, then switch to the new one from the start.
		t.readAvailable(real, st)
		t.flushPartial(real, st)
		t.flushPending(real, st)
		st.f.Close()
		delete(t.files, real)
		st = nil
		first = false
		delete(t.saved, real)
	}
	if st == nil {
		f, err := os.Open(real)
		if err != nil {
			return
		}
		st = &fileState{src: src, f: f, ino: ino}
		if sv, ok := t.saved[real]; ok && sv.Ino == ino && sv.Offset <= fi.Size() && bytes.HasPrefix(readHead(f), sv.Head) {
			st.offset = sv.Offset
			st.head = sv.Head
		} else if first {
			st.offset = fi.Size() // pre-existing history is not shipped
		}
		t.files[real] = st
		t.dirty = true
	}
	st.src = src
	if cur := readHead(st.f); fi.Size() < st.offset || !bytes.HasPrefix(cur, st.head) {
		// Truncated (copytruncate rotation).
		t.flushPending(real, st)
		st.offset = 0
		st.partial = nil
		st.head = nil
		t.dirty = true
	}
	if t.readAvailable(real, st) == 0 {
		// Nothing new for a whole poll interval: the collected record is complete.
		t.flushPending(real, st)
	}
	if len(st.head) < headLen {
		st.head = readHead(st.f)
	}
}

func readHead(f *os.File) []byte {
	b := make([]byte, headLen)
	n, _ := f.ReadAt(b, 0)
	return b[:n]
}

// readAvailable consumes new bytes and returns how many were read.
func (t *Tailer) readAvailable(real string, st *fileState) int {
	if _, err := st.f.Seek(st.offset, io.SeekStart); err != nil {
		return 0
	}
	buf := make([]byte, 64<<10)
	read := 0
	for read < readChunk {
		n, err := st.f.Read(buf)
		if n > 0 {
			read += n
			st.offset += int64(n)
			t.dirty = true
			t.consume(real, st, buf[:n])
		}
		if err != nil || n == 0 {
			break
		}
	}
	return read
}

func (t *Tailer) consume(real string, st *fileState, b []byte) {
	data := append(st.partial, b...)
	for {
		i := bytes.IndexByte(data, '\n')
		if i < 0 {
			break
		}
		t.line(real, st, data[:i], int64(i+1))
		data = data[i+1:]
	}
	for len(data) >= MaxLine {
		t.line(real, st, data[:MaxLine], MaxLine)
		data = data[MaxLine:]
	}
	st.partial = append([]byte(nil), data...)
}

func (t *Tailer) flushPartial(real string, st *fileState) {
	if len(st.partial) > 0 {
		t.line(real, st, st.partial, int64(len(st.partial)))
		st.partial = nil
	}
}

// line handles one complete line (n file bytes): emitted directly, or merged into the pending
// multi-line record when the source groups continuation lines.
func (t *Tailer) line(real string, st *fileState, line []byte, n int64) {
	start := multilineStart[st.src.Multiline]
	if start == nil {
		t.emitLine(real, st.src, line)
		return
	}
	line = bytes.TrimRight(line, "\r")
	if st.pending != nil && !start.Match(line) && len(st.pending)+1+len(line) <= MaxRecord {
		st.pending = append(append(st.pending, '\n'), line...)
		st.pendingN += n
		return
	}
	t.flushPending(real, st)
	st.pending = append([]byte(nil), line...)
	st.pendingN = n
}

func (t *Tailer) flushPending(real string, st *fileState) {
	if st.pending != nil {
		t.emitLine(real, st.src, st.pending)
		st.pending, st.pendingN = nil, 0
		t.dirty = true
	}
}

func (t *Tailer) emitLine(real string, src Source, line []byte) {
	line = bytes.TrimRight(line, "\r")
	if len(bytes.TrimSpace(line)) == 0 {
		return
	}
	hostPath := real
	if !t.fs.IsReal() {
		hostPath = "/" + strings.TrimPrefix(strings.TrimPrefix(real, filepath.Clean(t.fs.Root)), "/")
	}
	var rec obs.LogRecord
	if src.Kind == "access" {
		rec = ParseAccessLine(line)
	} else {
		rec = ParseLine(line, src.Format)
	}
	rec.Site = src.Site
	if src.SiteFromFile {
		if slug := strings.TrimSuffix(filepath.Base(hostPath), ".log"); slugRE.MatchString(slug) {
			rec.Site = slug
		}
	}
	rec.Service = src.Service
	rec.Kind = src.Kind
	if rec.Attrs == nil {
		rec.Attrs = map[string]string{}
	}
	rec.Attrs["log.file.path"] = hostPath
	rec.Attrs["log.file.name"] = filepath.Base(hostPath)
	t.sink.EmitLog(rec)
}

func (t *Tailer) saveLocked() {
	out := map[string]savedOffset{}
	for k, v := range t.saved {
		out[k] = v
	}
	for real, st := range t.files {
		// Offsets exclude the buffered partial line and pending record so they are re-read after a restart.
		out[real] = savedOffset{Ino: st.ino, Offset: st.offset - int64(len(st.partial)) - st.pendingN, Head: st.head}
	}
	t.saved = out
	b, _ := json.Marshal(out)
	if err := os.MkdirAll(filepath.Dir(t.statePath), 0o700); err == nil {
		tmp := t.statePath + ".tmp"
		if os.WriteFile(tmp, b, 0o600) == nil {
			_ = os.Rename(tmp, t.statePath)
		}
	}
	t.dirty = false
}

var laravelLevel = regexp.MustCompile(`\]\s+[A-Za-z0-9_-]+\.(DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY):`)
var bareLevel = regexp.MustCompile(`\b(DEBUG|INFO|NOTICE|WARN|WARNING|ERROR|CRITICAL|FATAL|ALERT|EMERGENCY|PANIC)\b`)

// NormalizeSeverity maps common level names to obs severities.
func NormalizeSeverity(s string) string {
	switch strings.ToUpper(strings.TrimSpace(s)) {
	case "TRACE":
		return "TRACE"
	case "DEBUG":
		return "DEBUG"
	case "", "INFO", "NOTICE", "INFORMATION":
		return "INFO"
	case "WARN", "WARNING":
		return "WARN"
	case "ERROR", "ERR":
		return "ERROR"
	case "CRITICAL", "CRIT", "ALERT", "EMERGENCY", "EMERG", "FATAL", "PANIC":
		return "FATAL"
	}
	return "INFO"
}

// ParseLine converts a raw line into a record. JSON lines (Monolog, pino, slog, zap …) have their
// message/level/time/trace fields extracted and remaining scalar fields turned into attributes.
func ParseLine(line []byte, format string) obs.LogRecord {
	rec := obs.LogRecord{Time: time.Now(), Severity: "INFO", Body: string(line)}
	if format == "json" || (format == "" && len(line) > 0 && line[0] == '{') {
		var m map[string]any
		if json.Unmarshal(line, &m) == nil {
			return fromJSON(m, rec)
		}
	}
	if mm := laravelLevel.FindSubmatch(line); mm != nil {
		rec.Severity = NormalizeSeverity(string(mm[1]))
	} else if mm := bareLevel.FindSubmatch(line); mm != nil {
		rec.Severity = NormalizeSeverity(string(mm[1]))
	}
	return rec
}

func fromJSON(m map[string]any, rec obs.LogRecord) obs.LogRecord {
	take := func(keys ...string) (string, bool) {
		for _, k := range keys {
			if v, ok := m[k]; ok {
				delete(m, k)
				switch t := v.(type) {
				case string:
					return t, true
				case float64:
					return fmt.Sprint(t), true
				}
			}
		}
		return "", false
	}
	if msg, ok := take("message", "msg"); ok {
		rec.Body = msg
	}
	if lvl, ok := take("level_name", "severity", "level"); ok {
		switch lvl {
		case "10":
			lvl = "TRACE"
		case "20":
			lvl = "DEBUG"
		case "30":
			lvl = "INFO"
		case "40":
			lvl = "WARN"
		case "50":
			lvl = "ERROR"
		case "60":
			lvl = "FATAL"
		}
		rec.Severity = NormalizeSeverity(lvl)
	}
	delete(m, "level")
	if ts, ok := take("time", "timestamp", "datetime", "@timestamp", "ts"); ok {
		for _, layout := range []string{time.RFC3339Nano, "2006-01-02T15:04:05.000000Z07:00", "2006-01-02 15:04:05"} {
			if tt, err := time.Parse(layout, ts); err == nil {
				rec.Time = tt
				break
			}
		}
	}
	if s, ok := take("trace_id", "traceId", "trace.id"); ok {
		if b, err := hex.DecodeString(s); err == nil && len(b) == 16 {
			rec.TraceID = b
		}
	}
	if s, ok := take("span_id", "spanId", "span.id"); ok {
		if b, err := hex.DecodeString(s); err == nil && len(b) == 8 {
			rec.SpanID = b
		}
	}
	rec.Attrs = map[string]string{}
	for k, v := range m {
		switch t := v.(type) {
		case string:
			rec.Attrs[k] = t
		case float64, bool:
			rec.Attrs[k] = fmt.Sprint(t)
		case nil:
		default:
			if b, err := json.Marshal(t); err == nil && len(b) <= 4096 {
				rec.Attrs[k] = string(b)
			}
		}
	}
	return rec
}
