//go:build linux

package deploy

import (
	"bytes"
	"errors"
	"syscall"
)

const aclDefaultXattr = "system.posix_acl_default"

// setGroupSharedACL sets groupSharedDefaultACL on dir. Filesystems without POSIX ACLs are skipped (the setgid
// group-writable mode still applies to existing files).
func setGroupSharedACL(dir string) (bool, error) {
	want := groupSharedDefaultACL()
	cur := make([]byte, 256)
	if n, err := syscall.Getxattr(dir, aclDefaultXattr, cur); err == nil && bytes.Equal(cur[:n], want) {
		return false, nil
	}
	err := syscall.Setxattr(dir, aclDefaultXattr, want, 0)
	if errors.Is(err, syscall.ENOTSUP) || errors.Is(err, syscall.EOPNOTSUPP) {
		return false, nil
	}
	return err == nil, err
}
