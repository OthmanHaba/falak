//go:build linux

package deploy

import (
	"bytes"
	"errors"
	"os"
	"os/user"
	"strconv"
	"syscall"
)

const (
	aclDefaultXattr = "system.posix_acl_default"
	aclAccessXattr  = "system.posix_acl_access"
)

func aclUnsupported(err error) bool {
	return errors.Is(err, syscall.ENOTSUP) || errors.Is(err, syscall.EOPNOTSUPP)
}

// setGroupSharedACL sets groupSharedDefaultACL on dir. Filesystems without POSIX ACLs are skipped (the setgid
// group-writable mode still applies to existing files).
func setGroupSharedACL(dir string) (bool, error) {
	return setXattrOnce(dir, aclDefaultXattr, groupSharedDefaultACL())
}

func setXattrOnce(p, name string, want []byte) (bool, error) {
	cur := make([]byte, 256)
	if n, err := syscall.Getxattr(p, name, cur); err == nil && bytes.Equal(cur[:n], want) {
		return false, nil
	}
	err := syscall.Setxattr(p, name, want, 0)
	if aclUnsupported(err) {
		return false, nil
	}
	return err == nil, err
}

// closeDir makes a release or shared directory 0750 so other local users cannot enter it (bootstrap/cache/config.php
// and .env hold the database password and APP_KEY), while the edge user keeps r-x through an ACL entry. Without
// ACL support the directory stays 0755 (a Caddy edge outside the site group would lose its static files) and false
// is returned.
func closeDir(dir string) (bool, error) {
	u, err := user.Lookup(EdgeUser)
	if err != nil {
		// No web server on this host: only the site user (owner) and root need the files.
		return true, os.Chmod(dir, 0o750)
	}
	uid, err := strconv.ParseUint(u.Uid, 10, 32)
	if err != nil {
		return false, err
	}
	if err := syscall.Setxattr(dir, aclAccessXattr, edgeAccessACL(0o750, uint32(uid)), 0); err != nil {
		if aclUnsupported(err) {
			return false, os.Chmod(dir, 0o755)
		}
		return false, err
	}
	return true, nil
}
