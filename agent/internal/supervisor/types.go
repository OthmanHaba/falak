// Package supervisor is falak-agent's built-in process supervisor (replaces supervisord). It converges
// the desired set of long-running programs sent by proc.apply, restarts them according to their policy
// with exponential backoff, captures stdout/stderr to size-capped log files and relays lines as OTLP logs.
package supervisor

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"path/filepath"
	"regexp"
	"syscall"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/cgroup"
)

// Program mirrors one entry of proc.apply `programs`.
type Program struct {
	Name         string            `json:"name"`
	Command      []string          `json:"command"`
	User         string            `json:"user,omitempty"`
	Cwd          string            `json:"cwd,omitempty"`
	Env          map[string]string `json:"env,omitempty"`
	Numprocs     int               `json:"numprocs,omitempty"`
	Autostart    *bool             `json:"autostart,omitempty"`
	Restart      string            `json:"restart,omitempty"`
	Backoff      *Backoff          `json:"backoff,omitempty"`
	StartSeconds *int              `json:"start_seconds,omitempty"`
	StopSignal   string            `json:"stop_signal,omitempty"`
	StopTimeoutS int               `json:"stop_timeout_s,omitempty"`
	Log          *LogSpec          `json:"log,omitempty"`
	Site         string            `json:"site,omitempty"`
	// Mask names the secret variables of env: their values are masked in the program's logs (file and OTLP).
	Mask []string `json:"mask,omitempty"`
	// Slice is the key of the proc.apply slice the program runs in (its limits); "" = the agent's own cgroup.
	Slice string `json:"slice,omitempty"`
	// OomScoreAdj is the program's OOM preference (-1000..1000; negative = killed last).
	OomScoreAdj int `json:"oom_score_adj,omitempty"`
	// MaxRestarts stops restarting an instance (state fatal) after this many restarts in a row that never reached
	// start_seconds of uptime; 0 = keep restarting.
	MaxRestarts int `json:"max_restarts,omitempty"`
}

// Backoff controls restart delays.
type Backoff struct {
	InitialMS int `json:"initial_ms,omitempty"`
	MaxMS     int `json:"max_ms,omitempty"`
}

// LogSpec controls output capture.
type LogSpec struct {
	Stdout   string `json:"stdout,omitempty"`
	Stderr   string `json:"stderr,omitempty"`
	MaxBytes *int64 `json:"max_bytes,omitempty"`
	// MaxFiles is how many rotated files are kept (<log>.1 … <log>.N; default 1).
	MaxFiles int   `json:"max_files,omitempty"`
	OTLP     *bool `json:"otlp,omitempty"`
}

// Restart policies.
const (
	RestartAlways    = "always"
	RestartOnFailure = "on-failure"
	RestartNever     = "never"
)

var nameRe = regexp.MustCompile(`^[a-z0-9][a-z0-9_.-]{0,62}$`)

// withDefaults returns a copy with every optional field filled, used both for hashing and running.
func (p Program) withDefaults(logDir string) Program {
	t := true
	if p.Numprocs <= 0 {
		p.Numprocs = 1
	}
	if p.Autostart == nil {
		p.Autostart = &t
	}
	if p.Restart == "" {
		p.Restart = RestartAlways
	}
	b := Backoff{InitialMS: 1000, MaxMS: 60000}
	if p.Backoff != nil {
		if p.Backoff.InitialMS > 0 {
			b.InitialMS = p.Backoff.InitialMS
		}
		if p.Backoff.MaxMS > 0 {
			b.MaxMS = p.Backoff.MaxMS
		}
	}
	if b.MaxMS < b.InitialMS {
		b.MaxMS = b.InitialMS
	}
	p.Backoff = &b
	if p.StartSeconds == nil {
		one := 1
		p.StartSeconds = &one
	}
	if p.StopSignal == "" {
		p.StopSignal = "TERM"
	}
	if p.StopTimeoutS <= 0 {
		p.StopTimeoutS = 30
	}
	l := LogSpec{}
	if p.Log != nil {
		l = *p.Log
	}
	if l.Stdout == "" {
		l.Stdout = filepath.Join(logDir, p.Name+".out.log")
	}
	if l.Stderr == "" {
		l.Stderr = filepath.Join(logDir, p.Name+".err.log")
	}
	if l.MaxBytes == nil {
		mb := int64(50 << 20)
		l.MaxBytes = &mb
	}
	if l.OTLP == nil {
		l.OTLP = &t
	}
	p.Log = &l
	return p
}

func (p Program) validate() error {
	if !nameRe.MatchString(p.Name) {
		return fmt.Errorf("invalid program name %q", p.Name)
	}
	if len(p.Command) == 0 || p.Command[0] == "" {
		return fmt.Errorf("program %s: empty command", p.Name)
	}
	switch p.Restart {
	case "", RestartAlways, RestartOnFailure, RestartNever:
	default:
		return fmt.Errorf("program %s: invalid restart policy %q", p.Name, p.Restart)
	}
	if p.StopSignal != "" {
		if _, ok := signals[p.StopSignal]; !ok {
			return fmt.Errorf("program %s: invalid stop_signal %q", p.Name, p.StopSignal)
		}
	}
	if p.Numprocs > 64 {
		return fmt.Errorf("program %s: numprocs > 64", p.Name)
	}
	if p.Slice != "" && !cgroup.NameRe.MatchString(p.Slice) {
		return fmt.Errorf("program %s: invalid slice %q", p.Name, p.Slice)
	}
	if p.OomScoreAdj < -1000 || p.OomScoreAdj > 1000 {
		return fmt.Errorf("program %s: oom_score_adj out of range", p.Name)
	}
	if p.MaxRestarts < 0 || (p.Log != nil && (p.Log.MaxFiles < 0 || p.Log.MaxFiles > 100)) {
		return fmt.Errorf("program %s: invalid max_restarts or log max_files", p.Name)
	}
	return nil
}

func (p Program) hash() string {
	b, _ := json.Marshal(p) // map keys are sorted by encoding/json → deterministic
	s := sha256.Sum256(b)
	return hex.EncodeToString(s[:])
}

func (p Program) backoffInitial() time.Duration {
	return time.Duration(p.Backoff.InitialMS) * time.Millisecond
}
func (p Program) backoffMax() time.Duration { return time.Duration(p.Backoff.MaxMS) * time.Millisecond }

var signals = map[string]syscall.Signal{
	"TERM": syscall.SIGTERM, "INT": syscall.SIGINT, "QUIT": syscall.SIGQUIT, "HUP": syscall.SIGHUP,
	"KILL": syscall.SIGKILL, "USR1": syscall.SIGUSR1, "USR2": syscall.SIGUSR2,
}

// Process states (proc.status).
const (
	StateStarting = "starting"
	StateRunning  = "running"
	StateBackoff  = "backoff"
	StateStopping = "stopping"
	StateStopped  = "stopped"
	StateExited   = "exited"
	StateFatal    = "fatal"
)

// ProcessStatus mirrors proc.status result entries.
type ProcessStatus struct {
	Name         string     `json:"name"`
	Instance     int        `json:"instance"`
	PID          int        `json:"pid,omitempty"`
	State        string     `json:"state"`
	Restarts     int        `json:"restarts"`
	StartedAt    *time.Time `json:"started_at,omitempty"`
	LastExitCode *int       `json:"last_exit_code,omitempty"`
}
