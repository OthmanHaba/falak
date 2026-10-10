package envlinks

import (
	"encoding/json"
	"errors"
	"io/fs"
	"os"
	"path/filepath"
	"sort"
)

// Split separates the masked variables of env (secrets, for the tmpfs) from the others (safe on disk).
func Split(env map[string]string, mask []string) (plain, secret map[string]string) {
	masked := map[string]bool{}
	for _, k := range mask {
		masked[k] = true
	}
	for k, v := range env {
		if masked[k] {
			if secret == nil {
				secret = map[string]string{}
			}
			secret[k] = v
			continue
		}
		if plain == nil {
			plain = map[string]string{}
		}
		plain[k] = v
	}
	return plain, secret
}

// SecretStore keeps the secret variables of persisted state (supervised programs, cron jobs) on the tmpfs, keyed by
// program or job name, while the rest of the state stays on disk. After a reboot the file is gone: entries that needed
// it wait until the control plane sends the full state again.
type SecretStore struct {
	Path string // a tmpfs file; "" keeps nothing (secrets are then lost on restart like after a reboot)
}

// Save replaces the stored secrets (0600 in a 0700 directory).
func (s SecretStore) Save(secrets map[string]map[string]string) error {
	if s.Path == "" {
		return nil
	}
	if len(secrets) == 0 {
		if err := os.Remove(s.Path); err != nil && !errors.Is(err, fs.ErrNotExist) {
			return err
		}
		return nil
	}
	b, err := json.Marshal(secrets)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(s.Path), 0o700); err != nil {
		return err
	}
	tmp := s.Path + ".tmp"
	if err := os.WriteFile(tmp, b, 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, s.Path)
}

// Load returns the stored secrets (empty when the file is gone).
func (s SecretStore) Load() map[string]map[string]string {
	out := map[string]map[string]string{}
	if s.Path == "" {
		return out
	}
	if b, err := os.ReadFile(s.Path); err == nil {
		_ = json.Unmarshal(b, &out)
	}
	return out
}

// Restore puts the stored secret values back into env; ok=false when one of keys has no stored value.
func Restore(env map[string]string, keys []string, stored map[string]string) (map[string]string, bool) {
	if len(keys) == 0 {
		return env, true
	}
	out := make(map[string]string, len(env)+len(keys))
	for k, v := range env {
		out[k] = v
	}
	for _, k := range keys {
		v, ok := stored[k]
		if !ok {
			return env, false
		}
		out[k] = v
	}
	return out, true
}

// Keys returns the sorted keys of m.
func Keys(m map[string]string) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	sort.Strings(out)
	return out
}
