package supervisor

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"log/slog"
	"os"
	"path/filepath"
	"sort"
	"sync"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/obs"
)

// Options configures a Supervisor.
type Options struct {
	StateDir string // proc.json lives here ("" = no persistence)
	LogDir   string // default program log directory (default /var/log/falak)
	Sink     obs.Sink
	Logger   *slog.Logger
}

// Supervisor owns all supervised programs.
type Supervisor struct {
	opts Options
	log  *slog.Logger

	applyMu  sync.Mutex // serializes apply/restart/shutdown
	mu       sync.Mutex
	programs map[string]*program
}

type program struct {
	spec      Program // defaults applied
	hash      string
	instances []*instance
	out, err  *rotFile
}

// New creates a Supervisor. Call Start to restore persisted state.
func New(o Options) *Supervisor {
	if o.LogDir == "" {
		o.LogDir = "/var/log/falak"
	}
	if o.Sink == nil {
		o.Sink = obs.Nop{}
	}
	if o.Logger == nil {
		o.Logger = slog.Default()
	}
	return &Supervisor{opts: o, log: o.Logger.With("component", "supervisor"), programs: map[string]*program{}}
}

// Register adds proc.apply, proc.restart and proc.status.
func (s *Supervisor) Register(reg *commands.Registry) {
	reg.Register("proc.apply", commands.Typed(func(ctx context.Context, p ApplyPayload, st commands.Stream) (any, error) {
		return s.Apply(ctx, p.Programs)
	}))
	reg.Register("proc.restart", commands.Typed(func(ctx context.Context, p RestartPayload, st commands.Stream) (any, error) {
		names, err := s.resolve(p.Names, p.Site)
		if err != nil {
			return nil, err
		}
		if err := s.Restart(ctx, names); err != nil {
			return nil, err
		}
		return RestartResult{Restarted: nonNil(names)}, nil
	}))
	reg.Register("proc.status", commands.Typed(func(ctx context.Context, p StatusPayload, st commands.Stream) (any, error) {
		return StatusResult{Processes: s.Status(p.Names)}, nil
	}))
}

// Payloads and results.
type (
	ApplyPayload struct {
		Programs []Program `json:"programs"`
	}
	ApplyResult struct {
		Changed   bool     `json:"changed"`
		Started   []string `json:"started"`
		Stopped   []string `json:"stopped"`
		Restarted []string `json:"restarted"`
		Unchanged []string `json:"unchanged"`
	}
	RestartPayload struct {
		Names []string `json:"names,omitempty"`
		Site  string   `json:"site,omitempty"`
	}
	RestartResult struct {
		Restarted []string `json:"restarted"`
	}
	StatusPayload struct {
		Names []string `json:"names,omitempty"`
	}
	StatusResult struct {
		Processes []ProcessStatus `json:"processes"`
	}
)

type stateFile struct {
	Programs []Program `json:"programs"`
}

// Start restores the persisted desired set. Programs keep running until Shutdown.
func (s *Supervisor) Start(ctx context.Context) error {
	if s.opts.StateDir == "" {
		return nil
	}
	b, err := os.ReadFile(filepath.Join(s.opts.StateDir, "proc.json"))
	if errors.Is(err, fs.ErrNotExist) {
		return nil
	}
	if err != nil {
		return err
	}
	var st stateFile
	if err := json.Unmarshal(b, &st); err != nil {
		return fmt.Errorf("proc.json: %w", err)
	}
	_, err = s.Apply(ctx, st.Programs)
	return err
}

// Shutdown gracefully stops every program (desired set stays persisted).
func (s *Supervisor) Shutdown() {
	s.applyMu.Lock()
	defer s.applyMu.Unlock()
	s.mu.Lock()
	progs := make([]*program, 0, len(s.programs))
	for _, p := range s.programs {
		progs = append(progs, p)
	}
	s.mu.Unlock()
	parallel(progs, func(p *program) { stopProgram(p) })
}

// Apply converges to the desired program set.
func (s *Supervisor) Apply(ctx context.Context, desired []Program) (ApplyResult, error) {
	res := ApplyResult{Started: []string{}, Stopped: []string{}, Restarted: []string{}, Unchanged: []string{}}
	want := map[string]Program{}
	for _, p := range desired {
		if err := p.validate(); err != nil {
			return res, &commands.PayloadError{Err: err}
		}
		if _, dup := want[p.Name]; dup {
			return res, &commands.PayloadError{Err: fmt.Errorf("duplicate program %q", p.Name)}
		}
		want[p.Name] = p.withDefaults(s.opts.LogDir)
	}
	s.applyMu.Lock()
	defer s.applyMu.Unlock()

	s.mu.Lock()
	var toStop []*program
	var toStart []Program
	for name, cur := range s.programs {
		w, ok := want[name]
		switch {
		case !ok:
			toStop = append(toStop, cur)
			res.Stopped = append(res.Stopped, name)
		case w.hash() != cur.hash:
			toStop = append(toStop, cur)
			toStart = append(toStart, w)
			res.Restarted = append(res.Restarted, name)
		default:
			res.Unchanged = append(res.Unchanged, name)
		}
	}
	for name, w := range want {
		if _, ok := s.programs[name]; !ok {
			toStart = append(toStart, w)
			res.Started = append(res.Started, name)
		}
	}
	for _, p := range toStop {
		delete(s.programs, p.spec.Name)
	}
	s.mu.Unlock()

	parallel(toStop, func(p *program) { stopProgram(p) })

	var errs []error
	for _, spec := range toStart {
		p, err := s.newProgram(spec)
		if err != nil {
			errs = append(errs, err)
			continue
		}
		s.mu.Lock()
		s.programs[spec.Name] = p
		s.mu.Unlock()
		if *spec.Autostart {
			for _, in := range p.instances {
				in.start()
			}
		}
	}
	for _, l := range [][]string{res.Started, res.Stopped, res.Restarted, res.Unchanged} {
		sort.Strings(l)
	}
	res.Changed = len(res.Started)+len(res.Stopped)+len(res.Restarted) > 0
	if err := s.persist(); err != nil {
		errs = append(errs, err)
	}
	s.log.Info("proc.apply", "started", res.Started, "stopped", res.Stopped, "restarted", res.Restarted)
	return res, errors.Join(errs...)
}

func (s *Supervisor) newProgram(spec Program) (*program, error) {
	out, err := openRot(spec.Log.Stdout, *spec.Log.MaxBytes)
	if err != nil {
		return nil, fmt.Errorf("program %s: stdout log: %w", spec.Name, err)
	}
	errf := out
	if spec.Log.Stderr != spec.Log.Stdout {
		if errf, err = openRot(spec.Log.Stderr, *spec.Log.MaxBytes); err != nil {
			out.Close()
			return nil, fmt.Errorf("program %s: stderr log: %w", spec.Name, err)
		}
	}
	p := &program{spec: spec, hash: spec.hash(), out: out, err: errf}
	for i := 0; i < spec.Numprocs; i++ {
		p.instances = append(p.instances, newInstance(s, spec, i, out, errf))
	}
	return p, nil
}

func stopProgram(p *program) {
	parallel(p.instances, func(in *instance) { in.stop() })
	p.out.Close()
	p.err.Close()
}

// RestartSite restarts every program of a site (none: nothing to do) and returns their names.
func (s *Supervisor) RestartSite(ctx context.Context, site string) ([]string, error) {
	names, err := s.resolve(nil, site)
	if err != nil || len(names) == 0 {
		return names, err
	}
	return names, s.Restart(ctx, names)
}

// Restart gracefully restarts the named programs (all when names is empty). Stopped/exited instances
// are started. Unknown names are an error.
func (s *Supervisor) Restart(ctx context.Context, names []string) error {
	s.applyMu.Lock()
	defer s.applyMu.Unlock()
	s.mu.Lock()
	var targets []*program
	if len(names) == 0 {
		for _, p := range s.programs {
			targets = append(targets, p)
		}
	} else {
		for _, n := range names {
			p, ok := s.programs[n]
			if !ok {
				s.mu.Unlock()
				return fmt.Errorf("unknown program %q", n)
			}
			targets = append(targets, p)
		}
	}
	s.mu.Unlock()
	parallel(targets, func(p *program) {
		for _, in := range p.instances {
			in.stop()
			in.mu.Lock()
			in.restarts = 0
			in.mu.Unlock()
			in.start()
		}
	})
	return ctx.Err()
}

// resolve expands a proc.restart selector.
func (s *Supervisor) resolve(names []string, site string) ([]string, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	var out []string
	if len(names) > 0 {
		for _, n := range names {
			p, ok := s.programs[n]
			if !ok {
				return nil, fmt.Errorf("unknown program %q", n)
			}
			if site == "" || p.spec.Site == site {
				out = append(out, n)
			}
		}
	} else {
		for n, p := range s.programs {
			if site == "" || p.spec.Site == site {
				out = append(out, n)
			}
		}
	}
	sort.Strings(out)
	return out, nil
}

// Status reports instance state (all programs when names is empty).
func (s *Supervisor) Status(names []string) []ProcessStatus {
	s.mu.Lock()
	var progs []*program
	filter := map[string]bool{}
	for _, n := range names {
		filter[n] = true
	}
	for n, p := range s.programs {
		if len(filter) == 0 || filter[n] {
			progs = append(progs, p)
		}
	}
	s.mu.Unlock()
	out := []ProcessStatus{}
	for _, p := range progs {
		for _, in := range p.instances {
			out = append(out, in.status())
		}
	}
	sort.Slice(out, func(i, j int) bool {
		if out[i].Name != out[j].Name {
			return out[i].Name < out[j].Name
		}
		return out[i].Instance < out[j].Instance
	})
	return out
}

func (s *Supervisor) persist() error {
	if s.opts.StateDir == "" {
		return nil
	}
	s.mu.Lock()
	st := stateFile{Programs: []Program{}}
	for _, p := range s.programs {
		st.Programs = append(st.Programs, p.spec)
	}
	s.mu.Unlock()
	sort.Slice(st.Programs, func(i, j int) bool { return st.Programs[i].Name < st.Programs[j].Name })
	b, err := json.MarshalIndent(st, "", "  ")
	if err != nil {
		return err
	}
	if err := os.MkdirAll(s.opts.StateDir, 0o700); err != nil {
		return err
	}
	path := filepath.Join(s.opts.StateDir, "proc.json")
	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, b, 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, path)
}

func parallel[T any](items []T, fn func(T)) {
	var wg sync.WaitGroup
	for _, it := range items {
		wg.Add(1)
		go func(it T) {
			defer wg.Done()
			fn(it)
		}(it)
	}
	wg.Wait()
}

func nonNil(s []string) []string {
	if s == nil {
		return []string{}
	}
	return s
}
