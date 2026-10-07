package volumes

import (
	"context"
	"fmt"
	"math"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/backupcrypt"
	"github.com/OthmanHaba/falak/agent/internal/commands"
)

// DrillPayload is volume.drill: restore a snapshot into a scratch directory next to the volumes, compare it with
// what the snapshot recorded, delete it.
type DrillPayload struct {
	Drill             string                 `json:"drill"`
	Source            Location               `json:"source"`
	SHA256            string                 `json:"sha256"`
	PlaintextSHA256   string                 `json:"plaintext_sha256,omitempty"`
	ArchiveBytes      int64                  `json:"archive_bytes,omitempty"`
	UncompressedBytes int64                  `json:"uncompressed_bytes,omitempty"`
	Encryption        backupcrypt.Encryption `json:"encryption"`
	Checks            DrillChecks            `json:"checks"`
}

// DrillChecks: the restored file count must be within TolerancePercent (default 10) of the snapshot's recorded one.
// The content itself is checked against the snapshot's authenticated SHA-256 (plaintext_sha256).
type DrillChecks struct {
	Files            int64   `json:"files,omitempty"`
	TolerancePercent float64 `json:"tolerance_percent,omitempty"`
}

// Secrets are the payload's secret values (masked in output).
func (p DrillPayload) Secrets() []string { return p.Encryption.Secrets() }

// DrillCheck is one check's outcome.
type DrillCheck struct {
	Name   string `json:"name"`
	Passed bool   `json:"passed"`
	Detail string `json:"detail,omitempty"`
}

// DrillResult is volume.drill's result (the shape of db.drill's).
type DrillResult struct {
	Status     string       `json:"status"`
	Reason     string       `json:"reason,omitempty"`
	Checks     []DrillCheck `json:"checks"`
	DownloadMS int64        `json:"download_ms"`
	RestoreMS  int64        `json:"restore_ms"`
	DurationMS int64        `json:"duration_ms"`
	Files      int64        `json:"files"`
	Bytes      int64        `json:"bytes"`
}

func (r *DrillResult) add(name string, passed bool, format string, a ...any) {
	r.Checks = append(r.Checks, DrillCheck{Name: name, Passed: passed, Detail: fmt.Sprintf(format, a...)})
	if !passed {
		r.Status = "failed"
	}
}

// Drill runs a volume restore drill. The scratch directory (<root>/.drills/<id>) is removed whatever happens.
func (s *Service) Drill(ctx context.Context, p DrillPayload, st commands.Stream) (any, error) {
	start := time.Now()
	if !idRe.MatchString(p.Drill) {
		return nil, payloadErr("invalid drill id %q", p.Drill)
	}
	if p.Source.Kind != "url" || !strings.HasPrefix(p.Source.URL, "https://") {
		return nil, payloadErr("source must be an https url")
	}
	if !shaRe.ok(p.SHA256) {
		return nil, payloadErr("invalid sha256")
	}
	if p.ArchiveBytes < 0 || p.UncompressedBytes < 0 || p.Checks.TolerancePercent < 0 || p.Checks.TolerancePercent > 100 {
		return nil, payloadErr("sizes and the tolerance must be positive (tolerance at most 100)")
	}
	if err := p.Encryption.Check(false); err != nil {
		return nil, &commands.PayloadError{Err: err}
	}
	res := DrillResult{Status: "passed", Checks: []DrillCheck{}}
	done := func() (any, error) {
		res.DurationMS = time.Since(start).Milliseconds()
		for _, c := range res.Checks {
			mark := map[bool]string{true: "ok", false: "FAILED"}[c.Passed]
			fmt.Fprintf(st.Stdout(), "%s %s: %s\n", mark, c.Name, c.Detail)
		}
		fmt.Fprintf(st.Stdout(), "drill %s in %s\n", res.Status, time.Duration(res.DurationMS)*time.Millisecond)
		return res, nil
	}

	// Room for the download (staging) and the unpacked data (scratch, on the volumes' filesystem).
	need := p.ArchiveBytes
	if need == 0 {
		need = p.UncompressedBytes
	}
	dir, err := s.staging(need)
	if err != nil {
		res.Status, res.Reason = "skipped", err.Error()
		return done()
	}
	scratchRel := filepath.Join(s.d.Root, ".drills", p.Drill)
	if err := s.room(s.d.FS.P(s.d.Root), p.UncompressedBytes, "the drill's scratch directory"); err != nil {
		res.Status, res.Reason = "skipped", err.Error()
		return done()
	}
	scratch := s.d.FS.P(scratchRel)
	defer func() {
		if err := os.RemoveAll(scratch); err != nil {
			fmt.Fprintf(st.Stderr(), "removing %s: %v\n", scratchRel, err)
		}
	}()
	_ = os.RemoveAll(scratch) // a leftover of an interrupted drill
	if err := os.MkdirAll(scratch, 0o700); err != nil {
		return nil, err
	}
	root, err := os.OpenRoot(scratch)
	if err != nil {
		return nil, err
	}
	defer root.Close()

	t := time.Now()
	file, err := s.fetch(ctx, dir, p.Source, p.SHA256, p.ArchiveBytes)
	res.DownloadMS = time.Since(t).Milliseconds()
	if err != nil {
		res.add("download", false, "%v", err)
		return done()
	}
	defer os.Remove(file)
	t = time.Now()
	stats, err := unpack(ctx, root, file, p.Encryption, p.PlaintextSHA256, p.UncompressedBytes)
	res.RestoreMS = time.Since(t).Milliseconds()
	if err != nil {
		res.add("restore", false, "%v", err)
		return done()
	}
	res.Files, res.Bytes = stats.files, stats.bytes
	res.add("restore", true, "%d files, %d bytes restored in %s", stats.files, stats.bytes, time.Duration(res.RestoreMS)*time.Millisecond)
	tol := p.Checks.TolerancePercent
	if tol == 0 {
		tol = 10
	}
	if p.Checks.Files > 0 {
		res.add("files", within(stats.files, p.Checks.Files, tol), "%d files, %d in the snapshot (±%g%%)", stats.files, p.Checks.Files, tol)
	}
	return done()
}

// within reports whether got is within tol percent of want (rounded up).
func within(got, want int64, tol float64) bool {
	allowed := int64(math.Ceil(float64(want) * tol / 100))
	d := got - want
	if d < 0 {
		d = -d
	}
	return d <= allowed
}
