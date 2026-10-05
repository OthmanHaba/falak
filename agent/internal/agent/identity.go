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
	"sort"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/config"
	"github.com/OthmanHaba/falak/agent/internal/enroll"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// PreviousDir (under the etc dir) keeps replaced identities, one directory per re-enrollment.
const PreviousDir = "previous"

// EnrollOnly is `falak-agent enroll`. With a token it always enrolls: on a machine that still has an identity
// (its server was deleted in Falak and a new install command runs here) the new identity replaces the old one only
// once it has been issued; the old files move to <etc>/previous/<UTC time>/. Without a token it enrolls only when
// there is no identity yet.
//
// A running falak-agent.service is stopped first (it could write a renewed certificate or telemetry.json over the
// new identity) and started again afterwards, whether enrollment succeeded or not.
func EnrollOnly(ctx context.Context, cfg config.Config, log *slog.Logger, out io.Writer, r runner.Runner) (err error) {
	if out == nil {
		out = io.Discard
	}
	paths := enroll.Paths{Dir: cfg.EtcDir}
	if cfg.Token == "" || !paths.Enrolled() {
		// Never restores an unfinished replacement (that is for `run`): an explicit token wins. Its backup stays in
		// previous/, marked done, so a later start does not bring it back over the new identity.
		if _, err := ensureEnrolled(ctx, cfg, log, false); err != nil {
			return err
		}
		if cfg.Token != "" {
			clearIncomplete(cfg)
		}
		return nil
	}
	if cfg.PanelURL == "" {
		return errors.New("enroll: set FALAK_PANEL_URL (or --panel)")
	}
	if r == nil {
		r = runner.Exec{}
	}
	if res, rerr := r.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"is-active", "--quiet", "falak-agent.service"}}); rerr == nil && res.ExitCode == 0 {
		fmt.Fprintln(out, "stopping falak-agent while its identity is replaced")
		if _, err := runner.Check(ctx, r, runner.Cmd{Name: "systemctl", Args: []string{"stop", "falak-agent.service"}}); err != nil {
			return fmt.Errorf("stop falak-agent: %w", err)
		}
		defer func() {
			if _, serr := runner.Check(context.WithoutCancel(ctx), r, runner.Cmd{Name: "systemctl", Args: []string{"start", "falak-agent.service"}}); serr != nil {
				err = errors.Join(err, fmt.Errorf("start falak-agent again: %w", serr))
				return
			}
			fmt.Fprintln(out, "started falak-agent again")
		}()
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
	fmt.Fprintf(out, "replaced the previous agent identity (agent %s) with agent %s; backup in %s\n", oldID, st.AgentID, backup)
	return nil
}

// incompleteMarker in a backup directory means the swap into place did not finish (crash, power loss).
const incompleteMarker = ".incomplete"

// clearIncomplete marks every unfinished replacement's backup as done (removes its incompleteMarker).
func clearIncomplete(cfg config.Config) {
	markers, _ := filepath.Glob(filepath.Join(cfg.EtcDir, PreviousDir, "*", incompleteMarker))
	for _, m := range markers {
		_ = os.Remove(m)
	}
}

// restoreIncomplete puts back the identity of an unfinished replacement when the etc dir has none: the newest
// previous/<time>/ with an incompleteMarker. Returns whether it restored one.
func restoreIncomplete(cfg config.Config, log *slog.Logger) bool {
	cur := enroll.Paths{Dir: cfg.EtcDir}
	if cur.Enrolled() {
		return false
	}
	markers, _ := filepath.Glob(filepath.Join(cfg.EtcDir, PreviousDir, "*", incompleteMarker))
	if len(markers) == 0 {
		return false
	}
	sort.Strings(markers) // UTC timestamps sort chronologically
	backup := filepath.Dir(markers[len(markers)-1])
	// With the whole old identity in the backup, identity files in the etc dir are half-installed new ones; without
	// it, the crash came while moving the old files out, and the ones still here are old.
	if (enroll.Paths{Dir: backup}).Enrolled() {
		for _, f := range cur.Files() {
			_ = os.Remove(f)
		}
	}
	names := []string{}
	for _, f := range cur.Files() {
		names = append(names, filepath.Base(f))
	}
	names = append(names, agentEtcFiles...)
	for _, n := range names {
		if err := os.Rename(filepath.Join(backup, n), filepath.Join(cfg.EtcDir, n)); err != nil && !errors.Is(err, fs.ErrNotExist) {
			log.Error("restoring the agent identity from an unfinished replacement failed", "backup", backup, "err", err)
			return false
		}
	}
	_ = os.Remove(filepath.Join(backup, incompleteMarker))
	if !cur.Enrolled() {
		log.Error("an unfinished replacement left no complete agent identity", "backup", backup)
		return false
	}
	st, _ := enroll.LoadState(cur)
	id := "unknown"
	if st != nil {
		id = st.AgentID
	}
	log.Warn("restored the previous agent identity: replacing it did not finish", "agent_id", id, "backup", backup)
	return true
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
	// Until the new identity is in place: restoreIncomplete puts the old one back after a crash in between.
	marker := filepath.Join(backup, incompleteMarker)
	if err := os.WriteFile(marker, nil, 0o600); err != nil {
		return "", err
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
			_ = os.Remove(marker)
			return "", fmt.Errorf("install new identity: %w", err)
		}
	}
	if err := os.Remove(marker); err != nil {
		return "", err
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
