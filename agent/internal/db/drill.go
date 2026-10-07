package db

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"math"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"syscall"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/backupcrypt"
	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/metrics"
)

// A restore drill (db.drill) proves a backup restores: a throwaway container of the instance's image digest, small,
// without network or published port, on a scratch directory, gets the backup restored into it and is checked (it has
// tables or keys, the row counts of the largest tables are close to those recorded at backup time, the user's check
// query returns rows); then the container and its data are deleted, whatever happened.

// LabelDrill marks a drill's container (its value is the drill id).
const LabelDrill = "falak.db.drill"

// Drill sizing: what must be free besides the drill's memory limit and its data.
const (
	drillMemoryMargin = 256 << 20
	drillDiskMargin   = 512 << 20
	// A restored database takes more room than its dump (indexes): this many times the dump's size.
	drillDataFactor = 3
)

// DrillInstance is the image and size of a drill's container.
type DrillInstance struct {
	Engine      string `json:"engine"`
	Version     string `json:"version"`
	Image       string `json:"image"`
	Digest      string `json:"digest"`
	MemoryBytes int64  `json:"memory_bytes"`
}

// DrillChecks are what a restored backup is held to.
type DrillChecks struct {
	// TableCounts are the counts recorded when the backup was taken (db.backup table_counts): the 10 largest tables
	// (Redis / Valkey: the total key count) must be within TolerancePercent of them.
	TableCounts      map[string]int64 `json:"table_counts,omitempty"`
	TolerancePercent float64          `json:"tolerance_percent,omitempty"`
	// Query is the user's check (falak-db query): it must return at least one row.
	Query string `json:"query,omitempty"`
}

// DrillPayload is db.drill.
type DrillPayload struct {
	Drill             string                 `json:"drill"`
	Instance          DrillInstance          `json:"instance"`
	Database          string                 `json:"database"`
	Source            Location               `json:"source"`
	SHA256            string                 `json:"sha256"`
	PlaintextSHA256   string                 `json:"plaintext_sha256,omitempty"`
	ArchiveBytes      int64                  `json:"archive_bytes,omitempty"`
	UncompressedBytes int64                  `json:"uncompressed_bytes,omitempty"`
	Encryption        backupcrypt.Encryption `json:"encryption"`
	Checks            DrillChecks            `json:"checks"`
	RegistryAuth      *docker.Auth           `json:"registry_auth,omitempty"`
}

// Secrets are the payload's secret values (masked in output).
func (p DrillPayload) Secrets() []string {
	out := p.Encryption.Secrets()
	if p.RegistryAuth != nil {
		out = append(out, p.RegistryAuth.Password)
	}
	return out
}

// DrillCheck is one check's outcome.
type DrillCheck struct {
	Name   string `json:"name"`
	Passed bool   `json:"passed"`
	Detail string `json:"detail,omitempty"`
}

// DrillResult is db.drill's result: passed (every check), failed (one did not) or skipped (the server lacks the room;
// Reason says what).
type DrillResult struct {
	Status     string       `json:"status"`
	Reason     string       `json:"reason,omitempty"`
	Checks     []DrillCheck `json:"checks"`
	DownloadMS int64        `json:"download_ms"`
	RestoreMS  int64        `json:"restore_ms"`
	DurationMS int64        `json:"duration_ms"`
	// Tables is the number of tables (Redis / Valkey: keys) the restored backup holds.
	Tables int64 `json:"tables"`
}

func (r *DrillResult) add(name string, passed bool, format string, a ...any) {
	r.Checks = append(r.Checks, DrillCheck{Name: name, Passed: passed, Detail: fmt.Sprintf(format, a...)})
	if !passed {
		r.Status = "failed"
	}
}

func drillContainer(id string) string { return "falak-db-drill-" + id }

func (p DrillPayload) validate() error {
	if err := checkID("drill", p.Drill); err != nil {
		return err
	}
	if err := checkEngine(p.Instance.Engine); err != nil {
		return err
	}
	if !versionRe.MatchString(p.Instance.Version) {
		return payloadErr("invalid instance.version %q", p.Instance.Version)
	}
	if p.Instance.Image == "" || strings.ContainsAny(p.Instance.Image, " \t\n@") {
		return payloadErr("invalid instance.image %q", p.Instance.Image)
	}
	if !digestRe.MatchString(p.Instance.Digest) {
		return payloadErr("instance.digest: a pinned sha256 digest is required")
	}
	if p.Instance.MemoryBytes < 32<<20 {
		return payloadErr("instance.memory_bytes must be at least 32 MiB")
	}
	if !isKeyValue(p.Instance.Engine) {
		if err := checkIdent("database", p.Database); err != nil {
			return err
		}
	} else if p.Checks.Query != "" {
		return payloadErr("checks.query: Redis / Valkey have no check query")
	}
	if p.Source.Kind != "url" || !strings.HasPrefix(p.Source.URL, "https://") {
		return payloadErr("source must be an https url")
	}
	if !sha256Re.MatchString(p.SHA256) {
		return payloadErr("invalid sha256")
	}
	if p.ArchiveBytes < 0 || p.UncompressedBytes < 0 || p.Checks.TolerancePercent < 0 || p.Checks.TolerancePercent > 100 {
		return payloadErr("sizes and the tolerance must be positive (tolerance at most 100)")
	}
	if err := p.Encryption.Check(false); err != nil {
		return &commands.PayloadError{Err: err}
	}
	return nil
}

// Drill runs a restore drill.
func (db *DB) Drill(ctx context.Context, p DrillPayload, st commands.Stream) (any, error) {
	start := time.Now()
	if err := p.validate(); err != nil {
		return nil, err
	}
	res := DrillResult{Status: "passed", Checks: []DrillCheck{}}
	if reason := db.drillRoom(p); reason != "" {
		res.Status, res.Reason = "skipped", reason
		fmt.Fprintf(st.Stdout(), "drill skipped: %s\n", reason)
		return res, nil
	}
	name := drillContainer(p.Drill)
	spec := InstanceSpec{ID: p.Drill, Engine: p.Instance.Engine, Version: p.Instance.Version, Image: p.Instance.Image, Digest: p.Instance.Digest}
	if _, err := db.ensureImage(ctx, spec, p.RegistryAuth, st); err != nil {
		return nil, err
	}

	dir := db.d.FS.P(filepath.Join(db.d.DrillRoot, p.Drill))
	secrets := db.d.FS.P(filepath.Join(db.d.SecretsDir, name))
	// Whatever happens next, the container and its data go.
	defer func() {
		cctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 2*time.Minute)
		defer cancel()
		if err := db.d.Docker.ContainerRemove(cctx, name); err != nil {
			fmt.Fprintf(st.Stderr(), "removing %s: %v\n", name, err)
		}
		_ = os.Chmod(secrets, 0o700)
		_ = os.RemoveAll(secrets)
		if err := os.RemoveAll(dir); err != nil {
			fmt.Fprintf(st.Stderr(), "removing %s: %v\n", dir, err)
		}
		fmt.Fprintf(st.Stdout(), "removed %s and its data\n", name)
	}()
	_ = db.d.Docker.ContainerRemove(ctx, name) // a leftover of an interrupted drill
	if err := db.prepareDrill(dir, secrets, p.Instance.Engine); err != nil {
		return nil, err
	}
	id, err := db.d.Docker.ContainerCreate(ctx, name, db.drillBody(p, spec, dir, secrets))
	if err != nil {
		return nil, err
	}
	if err := db.d.Docker.ContainerStart(ctx, id); err != nil {
		return nil, err
	}
	fmt.Fprintf(st.Stdout(), "started %s (%s %s, %d MiB)\n", name, p.Instance.Engine, p.Instance.Version, p.Instance.MemoryBytes>>20)
	if _, err := db.awaitHealthy(ctx, name); err != nil {
		return nil, err
	}

	t := time.Now()
	file, cleanup, err := db.fetchSource(ctx, p.Source, p.SHA256)
	res.DownloadMS = time.Since(t).Milliseconds()
	if err != nil {
		res.add("download", false, "%v", err)
		return db.drillDone(res, start, st), nil
	}
	defer cleanup()
	t = time.Now()
	n, err := db.restoreInto(ctx, name, p.Instance.Engine, p.Database, "", false, file, p.Encryption, p.PlaintextSHA256, st)
	res.RestoreMS = time.Since(t).Milliseconds()
	if err != nil {
		res.add("restore", false, "%v", err)
		return db.drillDone(res, start, st), nil
	}
	res.add("restore", true, "%d bytes restored in %s", n, time.Duration(res.RestoreMS)*time.Millisecond)

	counts, err := db.containerCounts(ctx, name, p.Instance.Engine, p.Database)
	if err != nil {
		res.add("tables", false, "counting rows: %v", err)
		return db.drillDone(res, start, st), nil
	}
	tol := p.Checks.TolerancePercent
	if tol == 0 {
		tol = 10
	}
	if isKeyValue(p.Instance.Engine) {
		got := sum(counts)
		res.Tables = got
		res.add("keys", got > 0, "%d keys", got)
		if len(p.Checks.TableCounts) > 0 {
			want := sum(p.Checks.TableCounts)
			res.add("key_count", within(got, want, tol), "%d keys, %d when backed up (±%g%%)", got, want, tol)
		}
	} else {
		res.Tables = int64(len(counts))
		res.add("tables", len(counts) > 0, "%d tables", len(counts))
		if len(p.Checks.TableCounts) > 0 {
			ok, detail := compareLargest(counts, p.Checks.TableCounts, tol, 10)
			res.add("row_counts", ok, "%s", detail)
		}
		if p.Checks.Query != "" {
			rows, err := db.checkQuery(ctx, name, p.Database, p.Checks.Query)
			if err != nil {
				res.add("query", false, "%v", err)
			} else {
				res.add("query", rows > 0, "%d rows", rows)
			}
		}
	}
	return db.drillDone(res, start, st), nil
}

func (db *DB) drillDone(res DrillResult, start time.Time, st commands.Stream) DrillResult {
	res.DurationMS = time.Since(start).Milliseconds()
	for _, c := range res.Checks {
		mark := "ok"
		if !c.Passed {
			mark = "FAILED"
		}
		fmt.Fprintf(st.Stdout(), "%s %s: %s\n", mark, c.Name, c.Detail)
	}
	fmt.Fprintf(st.Stdout(), "drill %s in %s\n", res.Status, time.Duration(res.DurationMS)*time.Millisecond)
	return res
}

// drillRoom says why the server can't hold the drill ("" when it can): its memory limit and the data must fit next to
// what runs.
func (db *DB) drillRoom(p DrillPayload) string {
	if avail, err := db.d.MemAvailable(); err == nil && avail < p.Instance.MemoryBytes+drillMemoryMargin {
		return fmt.Sprintf("%d MiB of memory available, the drill needs %d MiB", avail>>20, (p.Instance.MemoryBytes+drillMemoryMargin)>>20)
	}
	data := max(p.UncompressedBytes, p.ArchiveBytes)*drillDataFactor + drillDiskMargin
	stage := p.ArchiveBytes + drillDiskMargin
	for _, c := range []struct {
		dir  string
		need int64
	}{{db.d.FS.P(db.d.DrillRoot), data}, {db.d.TempDir, stage}} {
		dir := c.dir
		for dir != "/" && dir != "." {
			if _, err := os.Stat(dir); err == nil {
				break
			}
			dir = filepath.Dir(dir)
		}
		if free, err := db.d.FreeBytes(dir); err == nil && free < c.need {
			return fmt.Sprintf("%d MiB free in %s, the drill needs %d MiB", free>>20, dir, c.need>>20)
		}
	}
	return ""
}

// prepareDrill makes the drill's data, spool and config directories and its password file.
func (db *DB) prepareDrill(dir, secrets, engine string) error {
	for _, sub := range []string{"data", "spool", "conf"} {
		if err := os.MkdirAll(filepath.Join(dir, sub), 0o700); err != nil {
			return err
		}
	}
	if err := os.Chmod(filepath.Join(dir, "conf"), 0o755); err != nil {
		return err
	}
	if err := os.WriteFile(filepath.Join(dir, "conf", "settings.json"), []byte(`{"tls":false}`), 0o644); err != nil {
		return err
	}
	parent := filepath.Dir(secrets)
	if err := os.MkdirAll(parent, 0o700); err != nil {
		return err
	}
	if err := os.Mkdir(secrets, 0o555); err != nil && !errors.Is(err, fs.ErrExist) {
		return err
	}
	pw := make([]byte, 24)
	if _, err := rand.Read(pw); err != nil {
		return err
	}
	return writeSecret(secrets, "password", []byte(hex.EncodeToString(pw)))
}

// drillBody is the drill's container: the instance's image by digest, its memory limit, no network and no restart.
func (db *DB) drillBody(p DrillPayload, s InstanceSpec, dir, secrets string) docker.CreateBody {
	stop := 30
	b := docker.CreateBody{
		Image: s.ref(),
		Env:   []string{"FALAK_DB_SETTINGS_FILE=" + settingsFile, "FALAK_DB_SPOOL=" + spoolTarget, passwordEnv(s.Engine) + "=" + passwordPath},
		Labels: map[string]string{
			docker.LabelManaged: "true",
			LabelDrill:          p.Drill,
			LabelEngine:         s.Engine,
		},
		Healthcheck: &docker.Healthcheck{
			Test:        []string{"CMD", "falak-db", "health"},
			Interval:    int64(5 * time.Second),
			Timeout:     int64(25 * time.Second),
			Retries:     3,
			StartPeriod: int64(120 * time.Second),
		},
		StopTimeout: &stop,
		HostConfig: docker.HostConfig{
			Mounts: []docker.Mount{
				{Type: "bind", Source: filepath.Join(dir, "data"), Target: dataTarget(s.Engine, s.Version)},
				{Type: "bind", Source: filepath.Join(dir, "spool"), Target: spoolTarget},
				{Type: "bind", Source: secrets, Target: secretsTarget, ReadOnly: true},
				{Type: "bind", Source: filepath.Join(dir, "conf"), Target: confTarget, ReadOnly: true},
			},
			NetworkMode: "none",
			Memory:      p.Instance.MemoryBytes,
		},
	}
	if s.Engine == "postgres" {
		b.HostConfig.ShmSize = shmSize(p.Instance.MemoryBytes)
	}
	return b
}

// checkQuery runs the user's check query read-only in the drill's container (falak-db query) and returns its rows.
func (db *DB) checkQuery(ctx context.Context, container, database, query string) (int64, error) {
	out, _, err := db.execIn(ctx, container, strings.NewReader(query), nil, nil, "query", "--database", database)
	if err != nil {
		return 0, err
	}
	var r struct {
		Rows int64 `json:"rows"`
	}
	if err := json.Unmarshal([]byte(strings.TrimSpace(out)), &r); err != nil {
		return 0, fmt.Errorf("query: %w", err)
	}
	return r.Rows, nil
}

func sum(m map[string]int64) int64 {
	var n int64
	for _, v := range m {
		n += v
	}
	return n
}

// within reports whether got is within tol percent of want (rounded up: a small table may differ by one row).
func within(got, want int64, tol float64) bool {
	allowed := int64(math.Ceil(float64(want) * tol / 100))
	d := got - want
	if d < 0 {
		d = -d
	}
	return d <= allowed
}

// compareLargest compares the n largest tables recorded at backup time with the restored counts.
func compareLargest(got, want map[string]int64, tol float64, n int) (bool, string) {
	names := make([]string, 0, len(want))
	for t := range want {
		names = append(names, t)
	}
	slices.SortFunc(names, func(a, b string) int {
		if want[a] != want[b] {
			if want[a] > want[b] {
				return -1
			}
			return 1
		}
		return strings.Compare(a, b)
	})
	names = names[:min(n, len(names))]
	var bad []string
	for _, t := range names {
		g, ok := got[t]
		switch {
		case !ok:
			bad = append(bad, t+": missing")
		case !within(g, want[t], tol):
			bad = append(bad, fmt.Sprintf("%s: %d rows, %d when backed up", t, g, want[t]))
		}
	}
	if len(bad) > 0 {
		return false, fmt.Sprintf("outside ±%g%%: %s", tol, strings.Join(bad, "; "))
	}
	return true, fmt.Sprintf("the %d largest tables within ±%g%% of the counts at backup time", len(names), tol)
}

// memAvailable is MemAvailable of the host's /proc/meminfo.
func memAvailable(fsys hostfs.FS) func() (int64, error) {
	return func() (int64, error) {
		m, err := metrics.ReadMem(fsys)
		if err != nil {
			return 0, err
		}
		return m.Available, nil
	}
}

// freeBytes is the space available to unprivileged writers on the filesystem holding path.
func freeBytes(path string) (int64, error) {
	var st syscall.Statfs_t
	if err := syscall.Statfs(path, &st); err != nil {
		return 0, err
	}
	return int64(st.Bavail) * int64(st.Bsize), nil
}
