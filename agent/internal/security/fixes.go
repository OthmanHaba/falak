package security

import (
	"context"
	"errors"
	"fmt"
	"regexp"
	"sort"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/commands"
)

// Fix is one entry of the allowlist. The control plane names a fix by id; what it does is compiled in here.
type Fix struct {
	ID string
	// Disruptive fixes restart services, reboot or can lock people out: "Fix all safe" skips them and each one is
	// confirmed on its own.
	Disruptive bool
	// Undoable fixes keep a backup that security.undo restores for BackupTTL.
	Undoable bool
	// ControlPlane fixes are applied by the control plane (the firewall belongs to the Network module); the agent
	// only reports them.
	ControlPlane bool
	apply        func(s *Security, ctx context.Context, b *backup, p FixPayload, st commands.Stream) (changed bool, msg string, err error)
	// undo runs after the backup's files and settings are restored (reloads, restarts).
	undo func(s *Security, ctx context.Context, m Manifest, st commands.Stream) error
}

var fixes map[string]Fix

func init() {
	fixes = map[string]Fix{}
	for _, f := range []Fix{
		{ID: "ssh.harden", Disruptive: true, Undoable: true, apply: (*Security).fixSSH, undo: (*Security).undoSSH},
		{ID: "updates.unattended", Undoable: true, apply: (*Security).fixUnattended},
		{ID: "updates.install", Disruptive: true, apply: (*Security).fixInstallUpdates},
		{ID: "updates.reboot", Disruptive: true, Undoable: true, apply: (*Security).fixReboot, undo: (*Security).undoReboot},
		{ID: "fail2ban.sshd", Undoable: true, apply: (*Security).fixFail2ban, undo: (*Security).undoFail2ban},
		{ID: "docker.tcp_off", Disruptive: true, Undoable: true, apply: (*Security).fixDockerTCP, undo: (*Security).undoDockerTCP},
		{ID: "kernel.sysctl", Undoable: true, apply: (*Security).fixSysctl},
		{ID: "files.secret_permissions", Undoable: true, apply: (*Security).fixSecretPerms},
		{ID: "time.sync", Undoable: true, apply: (*Security).fixTimeSync, undo: (*Security).undoTimeSync},
		{ID: "firewall.apply", ControlPlane: true},
		{ID: "firewall.close_port", ControlPlane: true},
	} {
		fixes[f.ID] = f
	}
}

// fixFor looks a fix up by id; control-plane fixes carry parameters after a colon ("firewall.close_port:tcp:8080").
func fixFor(id string) (Fix, bool) {
	if id == "" {
		return Fix{}, false
	}
	base, _, _ := strings.Cut(id, ":")
	f, ok := fixes[base]
	return f, ok
}

// FixIDs lists the allowlist (sorted).
func FixIDs() []string {
	ids := make([]string, 0, len(fixes))
	for id := range fixes {
		ids = append(ids, id)
	}
	sort.Strings(ids)
	return ids
}

// FixPayload is security.fix.
type FixPayload struct {
	FixID string `json:"fix_id"`
	// RebootAt is when updates.reboot reboots ("HH:MM", server time, the next occurrence); default 04:00.
	RebootAt string `json:"reboot_at"`
}

// FixResult is its result.
type FixResult struct {
	FixID      string `json:"fix_id"`
	Changed    bool   `json:"changed"`
	BackupID   string `json:"backup_id,omitempty"`
	Disruptive bool   `json:"disruptive"`
	Undoable   bool   `json:"undoable"`
	Message    string `json:"message"`
}

// UndoPayload is security.undo.
type UndoPayload struct {
	FixID    string `json:"fix_id"`
	BackupID string `json:"backup_id"`
}

// UndoResult is its result.
type UndoResult struct {
	FixID    string `json:"fix_id"`
	BackupID string `json:"backup_id"`
	Restored int    `json:"restored"`
}

var rebootAtRe = regexp.MustCompile(`^([01][0-9]|2[0-3]):[0-5][0-9]$`)

// Fix applies one fix of the allowlist. A fix that fails is rolled back from its backup.
func (s *Security) Fix(ctx context.Context, p FixPayload, st commands.Stream) (any, error) {
	f, ok := fixes[p.FixID]
	if !ok {
		if g, ok := fixFor(p.FixID); ok && g.ControlPlane {
			return nil, &commands.PayloadError{Err: fmt.Errorf("%s is applied by the control plane, not the agent", p.FixID)}
		}
		return nil, &commands.PayloadError{Err: fmt.Errorf("unknown fix %q", p.FixID)}
	}
	if f.ControlPlane {
		return nil, &commands.PayloadError{Err: fmt.Errorf("%s is applied by the control plane, not the agent", p.FixID)}
	}
	if p.RebootAt != "" && !rebootAtRe.MatchString(p.RebootAt) {
		return nil, &commands.PayloadError{Err: fmt.Errorf("reboot_at must be HH:MM, got %q", p.RebootAt)}
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	s.prune()
	b, err := s.newBackup(f.ID)
	if err != nil {
		return nil, err
	}
	changed, msg, err := f.apply(s, ctx, b, p, st)
	if err != nil {
		if rerr := b.restore(context.WithoutCancel(ctx)); rerr != nil {
			err = errors.Join(err, fmt.Errorf("rolling back: %w", rerr))
		} else if !b.empty() {
			err = fmt.Errorf("%w (rolled back)", err)
		}
		b.discard()
		return nil, err
	}
	res := FixResult{FixID: f.ID, Changed: changed, Disruptive: f.Disruptive, Message: msg}
	if !changed || !f.Undoable || b.empty() {
		b.discard()
		return res, nil
	}
	res.BackupID, res.Undoable = b.m.ID, true
	fmt.Fprintf(st.Stdout(), "backup %s (undo within %s)\n", b.m.ID, BackupTTL)
	return res, nil
}

// Undo restores a fix's backup and removes it.
func (s *Security) Undo(ctx context.Context, p UndoPayload, st commands.Stream) (any, error) {
	f, ok := fixes[p.FixID]
	if !ok || f.ControlPlane || !f.Undoable {
		return nil, &commands.PayloadError{Err: fmt.Errorf("fix %q cannot be undone by the agent", p.FixID)}
	}
	if !BackupIDRe.MatchString(p.BackupID) {
		return nil, &commands.PayloadError{Err: fmt.Errorf("invalid backup id %q", p.BackupID)}
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	s.prune()
	b, err := s.loadBackup(p.BackupID)
	if err != nil {
		return nil, fmt.Errorf("backup %s is gone (undone already, or older than %s)", p.BackupID, BackupTTL)
	}
	if b.m.FixID != f.ID {
		return nil, &commands.PayloadError{Err: fmt.Errorf("backup %s belongs to %s, not %s", p.BackupID, b.m.FixID, f.ID)}
	}
	if err := b.restore(ctx); err != nil {
		return nil, err
	}
	if f.undo != nil {
		if err := f.undo(s, ctx, b.m, st); err != nil {
			return nil, err
		}
	}
	n := len(b.m.Files) + len(b.m.Sysctl) + len(b.m.State)
	b.discard()
	fmt.Fprintf(st.Stdout(), "restored %d item(s) from backup %s\n", n, p.BackupID)
	return UndoResult{FixID: f.ID, BackupID: p.BackupID, Restored: n}, nil
}
