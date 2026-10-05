package system

import (
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"regexp"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// UpgradePayload is system.upgrade_agent.
type UpgradePayload struct {
	Version string `json:"version"`
	URL     string `json:"url"`
	SHA256  string `json:"sha256"`
	Restart *bool  `json:"restart"`
}

// UpgradeResult is its result.
type UpgradeResult struct {
	Changed         bool   `json:"changed"`
	PreviousVersion string `json:"previous_version,omitempty"`
	Version         string `json:"version"`
}

var sha256Hex = regexp.MustCompile(`^[a-fA-F0-9]{64}$`)

// upgradeCheckTimeout bounds the pre-flight run of the downloaded binary.
const upgradeCheckTimeout = 15 * time.Second

// UpgradeAgent replaces the agent binary and restarts the service:
//
//  1. download to <bin>.new, verifying the SHA-256 (nothing is written on a mismatch);
//  2. pre-flight: `<bin>.new version` must run (a wrong-architecture or truncated build never replaces a working
//     agent) — its output is the version reported in the result;
//  3. keep the current binary as <bin>.prev and rename the new one over <bin> (atomic);
//  4. restart shortly after, so the finished event reaches the control plane first. The restarted agent reports
//     its version and binary checksum in the next heartbeat's facts.
//
// Idempotent: when <bin> already is that build, nothing is downloaded; the restart still happens if the running
// process is an older build (e.g. an earlier restart failed).
func (s *System) UpgradeAgent(ctx context.Context, p UpgradePayload, st commands.Stream) (any, error) {
	if p.URL == "" || !sha256Hex.MatchString(p.SHA256) {
		return nil, &commands.PayloadError{Err: errors.New("url and a hex sha256 are required")}
	}
	bin := s.d.BinaryPath
	res := UpgradeResult{PreviousVersion: s.d.AgentVersion, Version: p.Version}
	restart := p.Restart == nil || *p.Restart
	if cur, err := FileSHA256(bin); err == nil && strings.EqualFold(cur, p.SHA256) {
		running := ""
		if s.d.RunningSHA256 != nil {
			running = s.d.RunningSHA256()
		}
		if running == "" || strings.EqualFold(running, p.SHA256) {
			fmt.Fprintf(st.Stdout(), "falak-agent %s is already installed and running\n", s.d.AgentVersion)
			res.Version = s.d.AgentVersion
			return res, nil
		}
		fmt.Fprintf(st.Stdout(), "falak-agent %s is installed but not running yet\n", p.Version)
		res.Changed = true
		s.scheduleRestart(restart)
		return res, nil
	}
	tmp := bin + ".new"
	fmt.Fprintf(st.Stdout(), "downloading falak-agent %s from %s\n", p.Version, p.URL)
	if _, _, err := Download(ctx, s.d.HTTP, p.URL, strings.ToLower(p.SHA256), tmp, 0o755, nil); err != nil {
		return nil, err
	}
	fmt.Fprintf(st.Stdout(), "checksum verified (sha256 %s)\n", strings.ToLower(p.SHA256))
	reported, err := s.preflight(ctx, tmp)
	if err != nil {
		_ = os.Remove(tmp)
		return nil, fmt.Errorf("the downloaded falak-agent does not run on this host, keeping %s: %w", s.d.AgentVersion, err)
	}
	if reported != "" {
		res.Version = reported
	}
	prev := bin + ".prev"
	_ = os.Remove(prev)
	if err := keepCopy(bin, prev); err != nil {
		_ = os.Remove(tmp)
		return nil, fmt.Errorf("backup of the current binary: %w", err)
	}
	if err := os.Rename(tmp, bin); err != nil {
		_ = os.Remove(tmp)
		return nil, err
	}
	res.Changed = true
	fmt.Fprintf(st.Stdout(), "installed falak-agent %s at %s (previous binary kept as %s)\n", res.Version, bin, prev)
	s.scheduleRestart(restart)
	return res, nil
}

// preflight runs `<bin> version` and returns its trimmed output.
func (s *System) preflight(ctx context.Context, bin string) (string, error) {
	cctx, cancel := context.WithTimeout(ctx, upgradeCheckTimeout)
	defer cancel()
	r, err := runner.Check(cctx, s.d.Runner, runner.Cmd{Name: bin, Args: []string{"version"}})
	if err != nil {
		return "", err
	}
	out := strings.TrimSpace(string(r.Stdout))
	if i := strings.IndexByte(out, '\n'); i >= 0 {
		out = out[:i]
	}
	return out, nil
}

func (s *System) scheduleRestart(restart bool) {
	if !restart || s.d.Restart == nil {
		return
	}
	// Delay so the finished event is flushed to the control plane before we go down.
	time.AfterFunc(s.d.RestartDelay, func() {
		if err := s.d.Restart(); err != nil {
			s.d.Logger.Error("agent restart after upgrade failed", "err", err)
		}
	})
}

// keepCopy preserves bin as prev: a hard link when possible, else a copy (e.g. bin is a symlink into a read-only
// tree, or on another filesystem). A missing bin is not an error.
func keepCopy(bin, prev string) error {
	li, err := os.Lstat(bin)
	if errors.Is(err, os.ErrNotExist) {
		return nil
	} else if err != nil {
		return err
	}
	if li.Mode().IsRegular() && os.Link(bin, prev) == nil {
		return nil
	}
	in, err := os.Open(bin)
	if err != nil {
		return err
	}
	defer in.Close()
	out, err := os.OpenFile(prev, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o755)
	if err != nil {
		return err
	}
	if _, err := io.Copy(out, in); err != nil {
		out.Close()
		return err
	}
	return out.Close()
}
