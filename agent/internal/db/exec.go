package db

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// resultPrefix starts the stderr line carrying a streaming falak-db command's JSON result.
const resultPrefix = "falak-db-result: "

// exec runs `docker exec [-i] falak-db-<id> falak-db <args>` and returns its stdout (one JSON object). stdin, when
// set, is streamed to it; stdout, when set, receives the data stream instead (backups). args never hold a secret: they
// name files in the secrets directory.
func (db *DB) exec(ctx context.Context, id string, stdin io.Reader, stdout io.Writer, stderr io.Writer, args ...string) (string, string, error) {
	return db.execIn(ctx, Container(id), stdin, stdout, stderr, args...)
}

// execIn is exec in a container named directly (a restore drill's).
func (db *DB) execIn(ctx context.Context, container string, stdin io.Reader, stdout io.Writer, stderr io.Writer, args ...string) (string, string, error) {
	argv := []string{"exec"}
	if stdin != nil {
		argv = append(argv, "-i")
	}
	argv = append(argv, container, "falak-db")
	argv = append(argv, args...)
	var out, errBuf bytes.Buffer
	c := runner.Cmd{Name: "docker", Args: argv, Stdin: stdin, Stdout: &out, Stderr: &errBuf}
	if stdout != nil {
		c.Stdout = stdout
	}
	if stderr != nil {
		c.Stderr = io.MultiWriter(&errBuf, stderr)
	}
	res, err := db.d.Runner.Run(ctx, c)
	if err == nil && res.ExitCode != 0 {
		err = fmt.Errorf("falak-db %s: exit %d: %s", strings.Join(args[:min(2, len(args))], " "), res.ExitCode, lastLines(errBuf.String(), 5))
	}
	return out.String(), errBuf.String(), err
}

// changed parses falak-db's {"changed": bool} result.
func changed(out string) bool {
	var r struct {
		Changed bool `json:"changed"`
	}
	_ = json.Unmarshal([]byte(strings.TrimSpace(out)), &r)
	return r.Changed
}

// streamResult finds the JSON result line a streaming falak-db command printed on stderr.
func streamResult(stderr string, v any) error {
	for _, l := range strings.Split(stderr, "\n") {
		if strings.HasPrefix(l, resultPrefix) {
			return json.Unmarshal([]byte(strings.TrimPrefix(l, resultPrefix)), v)
		}
	}
	return errors.New("falak-db printed no result")
}

// lastLines keeps the last n non-empty lines (minus result lines) of a tool's stderr for an error message.
func lastLines(s string, n int) string {
	var keep []string
	for _, l := range strings.Split(s, "\n") {
		if l = strings.TrimSpace(l); l != "" && !strings.HasPrefix(l, resultPrefix) {
			keep = append(keep, l)
		}
	}
	if len(keep) > n {
		keep = keep[len(keep)-n:]
	}
	return strings.Join(keep, "; ")
}

// ---- secret files ----

// ensureSecretsDir makes the instance's secrets directory (parent 0700 root; the directory 0555, files 0444: the
// engine's user reads them inside the container, no host user but root reaches them). An existing directory is kept:
// it may be bind-mounted into the container, which would keep seeing a replaced one's old inode.
func (db *DB) ensureSecretsDir(id string) (string, bool, error) {
	parent := db.d.FS.P(db.d.SecretsDir)
	if err := os.MkdirAll(parent, 0o700); err != nil {
		return "", false, err
	}
	if err := os.Chmod(parent, 0o700); err != nil {
		return "", false, err
	}
	dir := db.d.FS.P(db.secretsDir(id))
	if _, err := os.Stat(dir); err == nil {
		return dir, false, nil
	}
	if err := os.Mkdir(dir, 0o555); err != nil && !errors.Is(err, fs.ErrExist) {
		return "", false, err
	}
	return dir, true, os.Chmod(dir, 0o555)
}

// writeSecret writes one file into the secrets directory: a temporary file renamed over name, so the container never
// reads half a password.
func writeSecret(dir, name string, content []byte) error {
	return writable(dir, func() error {
		tmp := filepath.Join(dir, ".tmp-"+randomName())
		if err := os.WriteFile(tmp, content, 0o444); err != nil {
			return err
		}
		if err := os.Chmod(tmp, 0o444); err != nil {
			os.Remove(tmp)
			return err
		}
		if err := os.Rename(tmp, filepath.Join(dir, name)); err != nil {
			os.Remove(tmp)
			return err
		}
		return nil
	})
}

// removeSecret deletes one file of the secrets directory.
func removeSecret(dir, name string) {
	_ = writable(dir, func() error { return os.Remove(filepath.Join(dir, name)) })
}

// writable opens the (0555) secrets directory for fn: root writes there anyway, other users (tests) need the bit.
func writable(dir string, fn func() error) error {
	if err := os.Chmod(dir, 0o755); err != nil {
		return err
	}
	defer os.Chmod(dir, 0o555)
	return fn()
}

func randomName() string {
	b := make([]byte, 8)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

// secretsMissing reports an instance whose secrets directory is gone (a reboot emptied /run).
func (db *DB) secretsMissing(id string) bool {
	_, err := os.Stat(db.d.FS.P(db.secretsDir(id)))
	return errors.Is(err, fs.ErrNotExist)
}

// withSpecFile writes a JSON spec (it may hold a password) as a dot-file in the secrets directory for the duration of
// fn, which gets its path inside the container.
func (db *DB) withSpecFile(id string, v any, fn func(inContainer string) error) error {
	dir := db.d.FS.P(db.secretsDir(id))
	if _, err := os.Stat(dir); err != nil {
		return fmt.Errorf("the secret files of %s are missing (restore them first): %w", Container(id), err)
	}
	b, err := json.Marshal(v)
	if err != nil {
		return err
	}
	name := ".spec-" + randomName() + ".json"
	if err := writeSecret(dir, name, b); err != nil {
		return err
	}
	defer removeSecret(dir, name)
	return fn(secretsTarget + "/" + name)
}
