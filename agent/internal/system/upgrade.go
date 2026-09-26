package system

import (
	"context"
	"errors"
	"os"
	"strings"
	"time"

	"github.com/kiln/agent/internal/commands"
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

// UpgradeAgent swaps the agent binary (keeping <bin>.prev) and schedules a restart.
func (s *System) UpgradeAgent(ctx context.Context, p UpgradePayload, st commands.Stream) (any, error) {
	if p.URL == "" || p.SHA256 == "" {
		return nil, &commands.PayloadError{Err: errors.New("url and sha256 are required")}
	}
	bin := s.d.BinaryPath
	res := UpgradeResult{PreviousVersion: s.d.AgentVersion, Version: p.Version}
	if cur, err := FileSHA256(bin); err == nil && strings.EqualFold(cur, p.SHA256) {
		return res, nil // already on this build
	}
	tmp := bin + ".new"
	if _, _, err := Download(ctx, s.d.HTTP, p.URL, p.SHA256, tmp, 0o755, nil); err != nil {
		return nil, err
	}
	prev := bin + ".prev"
	_ = os.Remove(prev)
	if _, err := os.Stat(bin); err == nil {
		if err := os.Link(bin, prev); err != nil {
			return nil, err
		}
	}
	if err := os.Rename(tmp, bin); err != nil {
		return nil, err
	}
	res.Changed = true
	st.Stdout().Write([]byte("installed kiln-agent " + p.Version + " at " + bin + "\n"))
	if (p.Restart == nil || *p.Restart) && s.d.Restart != nil {
		// Delay so the finished event is flushed to the control plane before we go down.
		time.AfterFunc(s.d.RestartDelay, func() {
			if err := s.d.Restart(); err != nil {
				s.d.Logger.Error("agent restart after upgrade failed", "err", err)
			}
		})
	}
	return res, nil
}
