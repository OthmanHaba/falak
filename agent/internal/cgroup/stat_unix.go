package cgroup

import (
	"os"
	"syscall"
)

// statOwner is a file's owner and group (0, 0 when the platform doesn't say).
func statOwner(st os.FileInfo) (uint32, uint32) {
	if sys, ok := st.Sys().(*syscall.Stat_t); ok {
		return sys.Uid, sys.Gid
	}
	return 0, 0
}
