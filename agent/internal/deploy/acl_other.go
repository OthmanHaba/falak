//go:build !linux

package deploy

import "os"

// setGroupSharedACL is a no-op off Linux (development hosts).
func setGroupSharedACL(string) (bool, error) { return false, nil }

// closeDir only sets the mode off Linux (development hosts have no edge user).
func closeDir(dir string) (bool, error) { return true, os.Chmod(dir, 0o750) }
