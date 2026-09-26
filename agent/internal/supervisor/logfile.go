package supervisor

import (
	"bytes"
	"os"
	"path/filepath"
	"sync"
	"time"

	"github.com/kiln/agent/internal/obs"
)

// rotFile is an append-only log file rotated to "<path>.1" when it exceeds max bytes.
// Shared by all instances of a program.
type rotFile struct {
	mu   sync.Mutex
	path string
	max  int64
	f    *os.File
	size int64
}

func openRot(path string, max int64) (*rotFile, error) {
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return nil, err
	}
	r := &rotFile{path: path, max: max}
	return r, r.open()
}

func (r *rotFile) open() error {
	f, err := os.OpenFile(r.path, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o640)
	if err != nil {
		return err
	}
	st, err := f.Stat()
	if err != nil {
		f.Close()
		return err
	}
	r.f, r.size = f, st.Size()
	return nil
}

func (r *rotFile) Write(p []byte) (int, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.f == nil {
		return len(p), nil
	}
	if r.max > 0 && r.size+int64(len(p)) > r.max && r.size > 0 {
		r.f.Close()
		_ = os.Rename(r.path, r.path+".1")
		if err := r.open(); err != nil {
			r.f = nil
			return len(p), nil
		}
	}
	n, err := r.f.Write(p)
	r.size += int64(n)
	return n, err
}

func (r *rotFile) Close() error {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.f == nil {
		return nil
	}
	err := r.f.Close()
	r.f = nil
	return err
}

// maxLine bounds a single relayed log line.
const maxLine = 16 << 10

// lineWriter writes to a rotFile and relays complete lines to an obs.Sink.
type lineWriter struct {
	file     *rotFile
	sink     obs.Sink // nil = no relay
	site     string
	stream   string
	name     string
	instance int
	buf      []byte
}

func (w *lineWriter) Write(p []byte) (int, error) {
	_, _ = w.file.Write(p)
	if w.sink == nil {
		return len(p), nil
	}
	w.buf = append(w.buf, p...)
	for {
		i := bytes.IndexByte(w.buf, '\n')
		if i < 0 {
			if len(w.buf) >= maxLine {
				w.emit(w.buf[:maxLine])
				w.buf = append(w.buf[:0], w.buf[maxLine:]...)
				continue
			}
			break
		}
		w.emit(w.buf[:i])
		w.buf = w.buf[i+1:]
	}
	if len(w.buf) == 0 {
		w.buf = nil
	}
	return len(p), nil
}

// Flush relays a trailing partial line.
func (w *lineWriter) Flush() {
	if w.sink != nil && len(w.buf) > 0 {
		w.emit(w.buf)
	}
	w.buf = nil
}

func (w *lineWriter) emit(line []byte) {
	line = bytes.TrimRight(line, "\r")
	if len(line) == 0 {
		return
	}
	sev := "INFO"
	if w.stream == "stderr" {
		sev = "WARN"
	}
	w.sink.EmitLog(obs.LogRecord{
		Time: time.Now(), Severity: sev, Body: string(line), Site: w.site,
		Attrs: map[string]string{
			"process.name": w.name, "process.instance": itoa(w.instance), "log.iostream": w.stream,
		},
	})
}

func itoa(i int) string {
	if i == 0 {
		return "0"
	}
	var b [20]byte
	n := len(b)
	neg := i < 0
	if neg {
		i = -i
	}
	for i > 0 {
		n--
		b[n] = byte('0' + i%10)
		i /= 10
	}
	if neg {
		n--
		b[n] = '-'
	}
	return string(b[n:])
}
