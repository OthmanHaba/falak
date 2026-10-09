package supervisor

import (
	"context"
	"errors"
	"log/slog"
	"os"
	"os/exec"
	"sort"
	"sync"
	"syscall"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/cgroup"
	"github.com/OthmanHaba/falak/agent/internal/redact"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// instance is one supervised OS process slot (<name>:<idx>) with its restart loop.
type instance struct {
	spec   Program
	idx    int
	stdout *rotFile
	stderr *rotFile
	sup    *Supervisor
	log    *slog.Logger

	mu        sync.Mutex
	state     string
	pid       int
	restarts  int
	startedAt time.Time
	lastExit  *int
	loop      bool          // restart loop active
	stopCh    chan struct{} // closed to request stop
	done      chan struct{} // closed when the loop exited
}

func newInstance(s *Supervisor, spec Program, idx int, out, errf *rotFile) *instance {
	return &instance{spec: spec, idx: idx, stdout: out, stderr: errf, sup: s,
		log: s.log.With("program", spec.Name, "instance", idx), state: StateStopped}
}

// start launches the restart loop if not already running.
func (in *instance) start() {
	in.mu.Lock()
	defer in.mu.Unlock()
	if in.loop {
		return
	}
	in.loop = true
	in.stopCh = make(chan struct{})
	in.done = make(chan struct{})
	go in.run(in.stopCh, in.done)
}

// stop requests a graceful stop and waits until the process group is gone.
func (in *instance) stop() {
	in.mu.Lock()
	if !in.loop {
		in.mu.Unlock()
		return
	}
	stopCh, done := in.stopCh, in.done
	select {
	case <-stopCh:
	default:
		close(stopCh)
	}
	in.mu.Unlock()
	<-done
}

func (in *instance) setState(st string) {
	in.mu.Lock()
	in.state = st
	in.mu.Unlock()
}

func (in *instance) run(stopCh, done chan struct{}) {
	defer func() {
		in.mu.Lock()
		in.loop = false
		in.pid = 0
		in.mu.Unlock()
		close(done)
	}()
	spec := in.spec
	backoff := time.Duration(0)
	quick := 0 // restarts in a row that never reached start_seconds
	for {
		select {
		case <-stopCh:
			in.setState(StateStopped)
			return
		default:
		}
		in.setState(StateStarting)
		started := time.Now()
		code, stopped := in.runOnce(stopCh)
		if stopped {
			in.setState(StateStopped)
			return
		}
		uptime := time.Since(started)
		in.mu.Lock()
		c := code
		in.lastExit = &c
		in.mu.Unlock()
		switch {
		case spec.Restart == RestartNever, spec.Restart == RestartOnFailure && code == 0:
			in.setState(StateExited)
			in.log.Info("program exited", "code", code)
			return
		}
		healthy := uptime >= time.Duration(*spec.StartSeconds)*time.Second
		if healthy {
			quick = 0
		} else {
			quick++
		}
		if spec.MaxRestarts > 0 && quick > spec.MaxRestarts {
			in.setState(StateFatal)
			in.log.Error("program keeps failing, giving up", "code", code, "max_restarts", spec.MaxRestarts)
			return
		}
		// Backoff resets after a healthy run of start_seconds.
		if healthy || backoff == 0 {
			backoff = spec.backoffInitial()
		} else {
			backoff *= 2
			if backoff > spec.backoffMax() {
				backoff = spec.backoffMax()
			}
		}
		in.mu.Lock()
		in.restarts++
		in.state = StateBackoff
		in.mu.Unlock()
		in.log.Warn("program exited, restarting", "code", code, "backoff", backoff)
		t := time.NewTimer(backoff)
		select {
		case <-stopCh:
			t.Stop()
			in.setState(StateStopped)
			return
		case <-t.C:
		}
	}
}

// runOnce starts the process and waits for it. stopped=true when it ended due to a stop request.
func (in *instance) runOnce(stopCh chan struct{}) (code int, stopped bool) {
	spec := in.spec
	outW := &lineWriter{file: in.stdout, site: spec.Site, stream: "stdout", name: spec.Name, instance: in.idx}
	errW := &lineWriter{file: in.stderr, site: spec.Site, stream: "stderr", name: spec.Name, instance: in.idx}
	if *spec.Log.OTLP && in.sup.opts.Sink != nil {
		outW.sink, errW.sink = in.sup.opts.Sink, in.sup.opts.Sink
	}
	defer outW.Flush()
	defer errW.Flush()
	// Secrets never reach the log files or the OTLP relay.
	secrets := redact.NewSet(redact.FromEnv(spec.Env, spec.Mask)...)
	maskOut, maskErr := redact.NewWriter(secrets, outW), redact.NewWriter(secrets, errW)
	defer maskErr.Flush()
	defer maskOut.Flush()

	var cred *syscall.Credential
	var home string
	if spec.User != "" {
		var err error
		if cred, home, err = runner.Credential(spec.User); err != nil {
			in.log.Error("resolve user", "err", err)
			_, _ = errW.Write([]byte("falak: " + err.Error() + "\n"))
			return 127, false
		}
	}
	argv, cred := in.sup.launch(spec, in.idx, cred)
	cmd := exec.Command(argv[0], argv[1:]...)
	cmd.Dir = spec.Cwd
	cmd.Stdout, cmd.Stderr = maskOut, maskErr
	cmd.WaitDelay = 2 * time.Second
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
	env := []string{
		"PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
		"LANG=C.UTF-8",
		"FALAK_PROCESS_NAME=" + spec.Name,
		"FALAK_PROCESS_INSTANCE=" + itoa(in.idx),
	}
	if spec.User != "" {
		cmd.SysProcAttr.Credential = cred // nil in a slice: setpriv drops to the user
		env = append(env, "HOME="+home, "USER="+spec.User, "LOGNAME="+spec.User)
	} else if h, err := os.UserHomeDir(); err == nil {
		env = append(env, "HOME="+h)
	}
	keys := make([]string, 0, len(spec.Env))
	for k := range spec.Env {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		env = append(env, k+"="+spec.Env[k])
	}
	cmd.Env = env

	if err := cmd.Start(); err != nil {
		in.log.Error("start failed", "err", err)
		_, _ = errW.Write([]byte("falak: start failed: " + err.Error() + "\n"))
		return 127, false
	}
	pid := cmd.Process.Pid
	if spec.OomScoreAdj != 0 {
		if err := cgroup.SetOOMScoreAdj(pid, spec.OomScoreAdj); err != nil {
			in.log.Warn("set oom_score_adj", "err", err)
		}
	}
	in.mu.Lock()
	in.pid = pid
	in.startedAt = time.Now()
	in.mu.Unlock()

	exited := make(chan error, 1)
	go func() { exited <- cmd.Wait() }()

	runningT := time.NewTimer(time.Duration(*spec.StartSeconds) * time.Second)
	defer runningT.Stop()
	for {
		select {
		case <-runningT.C:
			in.setState(StateRunning)
			continue
		case err := <-exited:
			in.mu.Lock()
			in.pid = 0
			in.mu.Unlock()
			return exitCode(cmd, err), false
		case <-stopCh:
			in.setState(StateStopping)
			sig := signals[spec.StopSignal]
			_ = syscall.Kill(-pid, sig)
			t := time.NewTimer(time.Duration(spec.StopTimeoutS) * time.Second)
			var err error
			select {
			case err = <-exited:
				t.Stop()
			case <-t.C:
				in.log.Warn("stop timeout, sending SIGKILL")
				_ = syscall.Kill(-pid, syscall.SIGKILL)
				err = <-exited
			}
			// Make sure nothing of the group survives (children ignoring the signal).
			_ = syscall.Kill(-pid, syscall.SIGKILL)
			code := exitCode(cmd, err)
			in.mu.Lock()
			in.pid = 0
			in.lastExit = &code
			in.mu.Unlock()
			return code, true
		}
	}
}

func exitCode(cmd *exec.Cmd, err error) int {
	if cmd.ProcessState != nil {
		if ws, ok := cmd.ProcessState.Sys().(syscall.WaitStatus); ok && ws.Signaled() {
			return 128 + int(ws.Signal())
		}
		return cmd.ProcessState.ExitCode()
	}
	var ee *exec.ExitError
	if errors.As(err, &ee) {
		return ee.ExitCode()
	}
	return -1
}

func (in *instance) status() ProcessStatus {
	in.mu.Lock()
	defer in.mu.Unlock()
	st := ProcessStatus{Name: in.spec.Name, Instance: in.idx, PID: in.pid, State: in.state,
		Restarts: in.restarts, LastExitCode: in.lastExit}
	if !in.startedAt.IsZero() {
		t := in.startedAt.UTC()
		st.StartedAt = &t
	}
	return st
}

// launch is the argv a program instance starts with. In a slice it is wrapped in a transient scope of that slice
// (cgroup.ScopeCommand): systemd-run runs as root and setpriv drops to the user, so no credential is set on the
// child; a leftover scope of the same name (the agent crashed while it ran) is stopped first.
func (s *Supervisor) launch(spec Program, idx int, cred *syscall.Credential) ([]string, *syscall.Credential) {
	if spec.Slice == "" {
		return spec.Command, cred
	}
	unit := "falak-proc-" + spec.Name + "-" + itoa(idx)
	_, _ = s.opts.Systemd.Run(context.Background(), runner.Cmd{Name: "systemctl", Args: []string{"stop", "--quiet", unit + ".scope"}})
	sp := cgroup.ScopeSpec{Slice: spec.Slice, Unit: unit, Command: spec.Command, SystemdVersion: s.systemdVersion()}
	if cred != nil {
		sp.AsUser, sp.UID, sp.GID = true, cred.Uid, cred.Gid
	}
	return cgroup.ScopeCommand(sp), nil
}
