package agent

import (
	"context"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"log/slog"
	"os"
	"path/filepath"
	"time"

	"github.com/kiln/agent/internal/config"
	"github.com/kiln/agent/internal/enroll"
	"github.com/kiln/agent/internal/hostfs"
)

// PreviousDir (under the etc dir) keeps replaced identities, one directory per re-enrollment.
const PreviousDir = "previous"

// EnrollOnly is `kiln-agent enroll`. With a token it always enrolls: on a machine that still has an identity
// (its server was deleted in Kiln and a new install command runs here) the new identity replaces the old one only
// once it has been issued; the old files move to <etc>/previous/<UTC time>/. Without a token it enrolls only when
// there is no identity yet.
func EnrollOnly(ctx context.Context, cfg config.Config, log *slog.Logger, out io.Writer) error {
	paths := enroll.Paths{Dir: cfg.EtcDir}
	if cfg.Token == "" || !paths.Enrolled() {
		_, err := ensureEnrolled(ctx, cfg, log)
		return err
	}
	if cfg.PanelURL == "" {
		return errors.New("enroll: set KILN_PANEL_URL (or --panel)")
	}
	old, _ := enroll.LoadState(paths)
	tmp, err := os.MkdirTemp(cfg.EtcDir, ".enroll-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(tmp)
	st, err := enrollInto(ctx, cfg, log, enroll.Paths{Dir: tmp})
	if err != nil {
		return err // the current identity is untouched
	}
	backup, err := replaceIdentity(cfg, tmp, time.Now())
	if err != nil {
		return err
	}
	oldID := "unknown"
	if old != nil && old.AgentID != "" {
		oldID = old.AgentID
	}
	log.Info("replaced agent identity", "previous_agent_id", oldID, "agent_id", st.AgentID, "backup", backup)
	if out != nil {
		fmt.Fprintf(out, "replaced the previous agent identity (agent %s) with agent %s; backup in %s\n", oldID, st.AgentID, backup)
	}
	return nil
}

// agentEtcFiles belong to one agent besides its identity: telemetry.json carries the old server's site ids.
var agentEtcFiles = []string{"telemetry.json"}

// replaceIdentity moves the current identity (and the agent's own state) into a new backup directory and the
// identity enrolled into newDir into its place. Sites, programs, cron jobs, certificates and databases stay: they
// belong to the machine, and the new server converges them.
func replaceIdentity(cfg config.Config, newDir string, now time.Time) (string, error) {
	cur, next := enroll.Paths{Dir: cfg.EtcDir}, enroll.Paths{Dir: newDir}
	prev := filepath.Join(cfg.EtcDir, PreviousDir)
	if err := os.MkdirAll(prev, 0o700); err != nil {
		return "", err
	}
	backup := filepath.Join(prev, now.UTC().Format("20060102T150405Z"))
	for i := 2; ; i++ {
		err := os.Mkdir(backup, 0o700)
		if err == nil {
			break
		}
		if !errors.Is(err, fs.ErrExist) || i > 100 {
			return "", err
		}
		backup = filepath.Join(prev, fmt.Sprintf("%s-%d", now.UTC().Format("20060102T150405Z"), i))
	}

	var moved []string // current files now in backup, for the rollback
	rollback := func() {
		for _, f := range moved {
			_ = os.Rename(filepath.Join(backup, filepath.Base(f)), f)
		}
	}
	olds := cur.Files()
	for _, n := range agentEtcFiles {
		olds = append(olds, filepath.Join(cfg.EtcDir, n))
	}
	for _, f := range olds {
		if err := os.Rename(f, filepath.Join(backup, filepath.Base(f))); err != nil {
			if errors.Is(err, fs.ErrNotExist) {
				continue
			}
			rollback()
			return "", err
		}
		moved = append(moved, f)
	}
	// agent.json is last (Files order): the identity counts as enrolled only once everything is in place.
	nf, cf := next.Files(), cur.Files()
	for i := range nf {
		if err := os.Rename(nf[i], cf[i]); err != nil {
			for _, f := range cf[:i] {
				_ = os.Remove(f)
			}
			rollback()
			return "", fmt.Errorf("install new identity: %w", err)
		}
	}

	// The old agent's command journal: its command ids mean nothing to the new server. The disk buffer holds
	// telemetry tagged with the deleted server; it is dropped.
	state := hostfs.FS{Root: cfg.HostRoot}.P(cfg.StateDir)
	if b, err := os.ReadFile(filepath.Join(state, "commands.json")); err == nil {
		if err := os.WriteFile(filepath.Join(backup, "commands.json"), b, 0o600); err == nil {
			_ = os.Remove(filepath.Join(state, "commands.json"))
		}
	}
	_ = os.RemoveAll(filepath.Join(state, "otlp-buffer"))
	return backup, nil
}
