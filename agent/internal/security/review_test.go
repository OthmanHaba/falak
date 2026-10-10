package security

import (
	"context"
	"strings"
	"testing"
)

// Undo never silently throws away an edit made after the fix: it lists the files and needs force.
func TestUndoRefusesFilesChangedSinceTheFix(t *testing.T) {
	s, _, root := newSec(t)
	sysctls(t, root, map[string]string{"kernel.dmesg_restrict": "0"})
	res := fix(t, s, FixPayload{FixID: "kernel.sysctl"})
	if e := manifest(t, root, res.BackupID).Files[0]; !e.AfterExists || e.AfterSHA256 == "" || e.AfterMode != 0o644 {
		t.Fatalf("not sealed: %+v", e)
	}
	put(t, root, SysctlFile, "# edited by hand\nkernel.dmesg_restrict = 1\n", 0o644)
	_, err := s.Undo(context.Background(), UndoPayload{FixID: "kernel.sysctl", BackupID: res.BackupID}, &stream{})
	if err == nil || !strings.Contains(err.Error(), "changed since the fix: "+SysctlFile) {
		t.Fatalf("%v", err)
	}
	if !fileExists(root, SysctlFile) || len(backups(t, root)) != 1 {
		t.Fatal("undo went ahead")
	}
	if _, err := s.Undo(context.Background(), UndoPayload{FixID: "kernel.sysctl", BackupID: res.BackupID, Force: true}, &stream{}); err != nil {
		t.Fatal(err)
	}
	if fileExists(root, SysctlFile) {
		t.Fatal("forced undo did not restore")
	}
}
