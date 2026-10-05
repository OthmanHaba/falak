package cli

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
)

// Credentials are stored in <config dir>/falak/credentials.json with mode 0600.
type Credentials struct {
	URL          string `json:"url"`
	Token        string `json:"token"`
	User         string `json:"user,omitempty"`
	Organization string `json:"organization,omitempty"`
}

// ConfigDir resolves the CLI config dir: $FALAK_CONFIG_DIR, else os.UserConfigDir()/falak
// (~/Library/Application Support/falak on macOS, $XDG_CONFIG_HOME/falak or ~/.config/falak on Linux).
func ConfigDir(getenv func(string) string) (string, error) {
	if d := getenv("FALAK_CONFIG_DIR"); d != "" {
		return d, nil
	}
	base, err := os.UserConfigDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(base, "falak"), nil
}

func credentialsPath(dir string) string { return filepath.Join(dir, "credentials.json") }

// LoadCredentials returns zero Credentials when none are stored.
func LoadCredentials(dir string) (Credentials, error) {
	var c Credentials
	b, err := os.ReadFile(credentialsPath(dir))
	if errors.Is(err, os.ErrNotExist) {
		return c, nil
	}
	if err != nil {
		return c, err
	}
	return c, json.Unmarshal(b, &c)
}

// SaveCredentials writes atomically with 0600 (dir 0700).
func SaveCredentials(dir string, c Credentials) error {
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return err
	}
	b, err := json.MarshalIndent(c, "", "  ")
	if err != nil {
		return err
	}
	tmp, err := os.CreateTemp(dir, ".credentials-*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if err := tmp.Chmod(0o600); err != nil {
		tmp.Close()
		return err
	}
	if _, err := tmp.Write(append(b, '\n')); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	return os.Rename(tmp.Name(), credentialsPath(dir))
}

// DeleteCredentials removes stored credentials (no error when absent).
func DeleteCredentials(dir string) error {
	err := os.Remove(credentialsPath(dir))
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}
	return err
}
