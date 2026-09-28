//go:build !linux

package deploy

// setGroupSharedACL is a no-op off Linux (development hosts).
func setGroupSharedACL(string) (bool, error) { return false, nil }
