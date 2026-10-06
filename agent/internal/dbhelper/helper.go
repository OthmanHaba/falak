package dbhelper

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"os/user"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// Helper runs falak-db operations for one engine. Its fields are the process's environment, injected so tests can
// replace them.
type Helper struct {
	Engine Engine
	Env    Env
	Run    Runner
	Stdin  io.Reader
	Stdout io.Writer
	Stderr io.Writer
	// Root prefixes every absolute path falak-db writes or reads in the image (tests); "" in production.
	Root string
	// Exec replaces the process (syscall.Exec in production).
	Exec  func(argv0 string, argv, env []string) error
	Now   func() time.Time
	Sleep func(time.Duration)
	// Chown changes ownership to the engine's user; nil disables it (tests, or not running as root).
	Chown func(path string, uid, gid int) error
}

func (h *Helper) path(p string) string {
	if h.Root == "" {
		return p
	}
	return filepath.Join(h.Root, p)
}

// result prints the JSON result of a non-streaming command on stdout.
func (h *Helper) result(v any) error {
	b, err := json.Marshal(v)
	if err != nil {
		return err
	}
	_, err = fmt.Fprintf(h.Stdout, "%s\n", b)
	return err
}

// ResultPrefix starts the line on stderr carrying the JSON result of a command whose stdout is a data stream.
const ResultPrefix = "falak-db-result: "

func (h *Helper) streamResult(v any) error {
	b, err := json.Marshal(v)
	if err != nil {
		return err
	}
	_, err = fmt.Fprintf(h.Stderr, "%s%s\n", ResultPrefix, b)
	return err
}

// owner returns the engine user's uid/gid, or ok=false when ownership is not managed.
func (h *Helper) owner() (uid, gid int, ok bool, err error) {
	if h.Chown == nil {
		return 0, 0, false, nil
	}
	u, err := user.Lookup(h.Engine.user())
	if err != nil {
		return 0, 0, false, fmt.Errorf("user %s: %w", h.Engine.user(), err)
	}
	uid, _ = strconv.Atoi(u.Uid)
	gid, _ = strconv.Atoi(u.Gid)
	return uid, gid, true, nil
}

func (h *Helper) chown(paths ...string) error {
	uid, gid, ok, err := h.owner()
	if err != nil || !ok {
		return err
	}
	for _, p := range paths {
		if err := h.Chown(p, uid, gid); err != nil {
			return err
		}
	}
	return nil
}

func (h *Helper) chownTree(root string) error {
	uid, gid, ok, err := h.owner()
	if err != nil || !ok {
		return err
	}
	return filepath.WalkDir(root, func(p string, d os.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if d.Type()&os.ModeSymlink != 0 {
			return nil
		}
		return h.Chown(p, uid, gid)
	})
}

func (h *Helper) pgUser() string { return h.Env.or("POSTGRES_USER", "postgres") }

// psql runs one SQL statement as the superuser over the socket and returns the unaligned output.
func (h *Helper) psql(ctx context.Context, sql string) (string, error) {
	out, err := output(ctx, h.Run, Cmd{Name: "psql", Args: []string{
		"-h", pgSocketDir, "-p", strconv.Itoa(pgPort), "-U", h.pgUser(), "-d", "postgres",
		"-AtqX", "-v", "ON_ERROR_STOP=1", "-c", sql,
	}})
	return strings.TrimSpace(out), err
}

// mysqlDefaults writes a client option file holding the superuser's credentials into a private temporary directory,
// so passwords never appear in argv or the environment. The caller removes it with the returned func.
func (h *Helper) mysqlDefaults() (string, func(), error) {
	pw, err := readPassword(h.Engine, h.Env)
	if err != nil {
		return "", nil, err
	}
	dir, err := os.MkdirTemp("", "falak-db-")
	if err != nil {
		return "", nil, err
	}
	cleanup := func() { os.RemoveAll(dir) }
	esc := strings.NewReplacer(`\`, `\\`, `"`, `\"`).Replace(pw)
	var b strings.Builder
	for _, group := range []string{"client", "xtrabackup", "mariadb-backup", "mariabackup"} {
		fmt.Fprintf(&b, "[%s]\nuser=root\npassword=\"%s\"\nsocket=%s\n\n", group, esc, mySocket)
	}
	f := filepath.Join(dir, "client.cnf")
	if err := os.WriteFile(f, []byte(b.String()), 0o600); err != nil {
		cleanup()
		return "", nil, err
	}
	return f, cleanup, nil
}

// mysqlQuery runs SQL as root over the socket, returning tab-separated rows without headers.
func (h *Helper) mysqlQuery(ctx context.Context, defaults, sql string) (string, error) {
	return output(ctx, h.Run, Cmd{Name: h.Engine.client("mysql"), Args: []string{
		"--defaults-extra-file=" + defaults, "-N", "-B", "-e", sql,
	}})
}

// kvCmd runs a redis-cli/valkey-cli command over the server's private socket. The password reaches the CLI through
// REDISCLI_AUTH (its environment only), never argv.
func (h *Helper) kvCmd(ctx context.Context, args ...string) (string, error) {
	pw, err := readPassword(h.Engine, h.Env)
	if err != nil {
		return "", err
	}
	out, err := output(ctx, h.Run, Cmd{
		Name: h.Engine.kvCLI(),
		Args: append([]string{"-s", kvSocket, "--no-auth-warning"}, args...),
		Env:  []string{"REDISCLI_AUTH=" + pw},
	})
	out = strings.TrimSpace(out)
	if err != nil {
		return out, err
	}
	for _, p := range []string{"ERR", "NOAUTH", "WRONGPASS", "LOADING", "NOPERM", "MISCONF"} {
		if strings.HasPrefix(out, p) {
			return out, fmt.Errorf("%s: %s", h.Engine.kvCLI(), firstLine(out))
		}
	}
	return out, nil
}

func firstLine(s string) string {
	if i := strings.IndexByte(s, '\n'); i >= 0 {
		return s[:i]
	}
	return s
}

// identifier names a database. Restricted so it can never be read as a connection string or an option.
var identifier = regexp.MustCompile(`^[A-Za-z0-9_][A-Za-z0-9_$-]{0,63}$`)

func checkDatabase(e Engine, name string) error {
	if e.kv() {
		if name != "" {
			return usageErr("--database is not used by %s", e)
		}
		return nil
	}
	if !identifier.MatchString(name) {
		return usageErr("--database: invalid or missing database name")
	}
	return nil
}

// emptyDir reports whether dir is missing or holds nothing but a filesystem's lost+found.
func emptyDir(dir string) (bool, error) {
	entries, err := os.ReadDir(dir)
	if os.IsNotExist(err) {
		return true, nil
	}
	if err != nil {
		return false, err
	}
	for _, e := range entries {
		if e.Name() != "lost+found" {
			return false, nil
		}
	}
	return true, nil
}

// safeShellPath is a path that may be embedded in postgres' restore_command, which postgres runs through /bin/sh.
var safeShellPath = regexp.MustCompile(`^/[A-Za-z0-9/._-]*$`)
