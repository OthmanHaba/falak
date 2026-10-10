package security

import "syscall"

// ctime is a file's status change time in nanoseconds (chmod, chown, a new file).
func ctime(st *syscall.Stat_t) int64 { return st.Ctimespec.Nano() }
