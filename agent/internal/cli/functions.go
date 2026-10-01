package cli

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/kiln/agent/internal/cli/api"
)

// ExitConflict: `kiln fn deploy` found a newer version than its base (pull, or deploy with --force).
const ExitConflict = 4

// functionMetaFile links a local directory to a function (written by `kiln fn pull`, updated by deploys).
const functionMetaFile = ".kiln-function.json"

type functionMeta struct {
	SiteID        string   `json:"site_id"`
	Slug          string   `json:"slug"`
	BaseVersionID string   `json:"base_version_id"`
	Entrypoint    string   `json:"entrypoint"`
	Files         []string `json:"files"`
}

func functionCommands() *command {
	return &command{name: "fn", summary: "Cloud Functions", subs: []*command{
		{name: "list", summary: "list functions", run: cmdFnList},
		{name: "pull", args: "<fn> [dir]", summary: "download a function's newest code", run: cmdFnPull},
		{name: "deploy", args: "<fn> [dir] [-m MESSAGE] [--force] [--wait]", summary: "deploy local code as a new version", run: cmdFnDeploy},
		{name: "versions", args: "<fn>", summary: "list a function's versions", run: cmdFnVersions},
		{name: "rollback", args: "<fn> <version> [--wait]", summary: "deploy an earlier version again", run: cmdFnRollback},
		{name: "run", args: "<fn> <schedule>", summary: "run a schedule now and stream its output", run: cmdFnRun},
		{name: "logs", args: "<fn> [--follow] [--since 1h]", summary: "show a function's logs", run: func(ctx context.Context, a *App, args []string) error { return cmdLogs(ctx, a, args) }},
		{name: "invoke", args: "<fn> [path] [-X METHOD] [-d BODY|@FILE] [-H 'K: V']…", summary: "send an HTTP request to a function", run: cmdFnInvoke},
	}}
}

func cmdFnList(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("fn list")
	if _, err := a.parse(fs, args, 0, 0, ""); err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	fns, err := c.Functions(ctx)
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(fns)
	}
	rows := make([][]string, 0, len(fns))
	for _, f := range fns {
		live := "-"
		if f.Live != nil {
			live = fmt.Sprintf("v%d (%s)", f.Live.Number, f.Live.ShortHash)
		}
		rows = append(rows, []string{f.Slug, f.Runtime, live, dash(f.URL)})
	}
	a.table([]string{"FUNCTION", "RUNTIME", "LIVE", "URL"}, rows)
	return nil
}

func cmdFnPull(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("fn pull")
	pos, err := a.parse(fs, args, 1, 2, "<fn> [dir]")
	if err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	fn, err := c.Function(ctx, pos[0])
	if err != nil {
		return err
	}
	if fn.Head == nil {
		return fmt.Errorf("%s has no code yet", fn.Site.Slug)
	}
	dir := fn.Site.Slug
	if len(pos) == 2 {
		dir = pos[1]
	}
	paths := make([]string, 0, len(fn.Head.Files))
	for p := range fn.Head.Files {
		paths = append(paths, p)
	}
	sort.Strings(paths)
	// Files an earlier pull or deploy wrote that this version no longer has (only those: never the user's others).
	if old, ok, _ := readFunctionMeta(dir); ok && old.SiteID == fn.Site.ID {
		for _, p := range old.Files {
			if _, keep := fn.Head.Files[p]; keep {
				continue
			}
			if target, err := safeJoin(dir, p); err == nil {
				if err := os.Remove(target); err == nil {
					fmt.Fprintf(a.Stderr, "kiln: removed %s (not in v%d)\n", p, fn.Head.Number)
				}
			}
		}
	}
	for _, p := range paths {
		target, err := safeJoin(dir, p)
		if err != nil {
			return err
		}
		if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
			return err
		}
		if err := os.WriteFile(target, []byte(fn.Head.Files[p]), 0o644); err != nil {
			return err
		}
	}
	meta := functionMeta{SiteID: fn.Site.ID, Slug: fn.Site.Slug, BaseVersionID: fn.Head.ID, Entrypoint: fn.Entrypoint, Files: paths}
	if err := writeFunctionMeta(dir, meta); err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(map[string]any{"dir": dir, "version": fn.Head.Number, "files": paths})
	}
	fmt.Fprintf(a.Stdout, "Pulled v%d of %s (%d file(s)) into %s\n", fn.Head.Number, fn.Site.Slug, len(paths), dir)
	return nil
}

func cmdFnDeploy(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("fn deploy")
	message := fs.String("m", "", "what changed (the version's message)")
	force := fs.Bool("force", false, "deploy even if someone deployed a newer version after your base")
	wait := fs.Bool("wait", false, "stream output and wait for the deployment to finish")
	pos, err := a.parse(fs, args, 1, 2, "<fn> [dir] [-m MESSAGE] [--force] [--wait]")
	if err != nil {
		return err
	}
	dir := "."
	if len(pos) == 2 {
		dir = pos[1]
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	fn, err := c.Function(ctx, pos[0])
	if err != nil {
		return err
	}
	meta, hasMeta, err := readFunctionMeta(dir)
	if err != nil {
		return err
	}
	if hasMeta && meta.SiteID != "" && meta.SiteID != fn.Site.ID {
		return usagef("%s belongs to %s, not %s", filepath.Join(dir, functionMetaFile), meta.Slug, fn.Site.Slug)
	}

	// The whole directory is the function (minus dependencies, VCS and dot-files; see readFunctionDir).
	files, skipped, err := readFunctionDir(dir)
	if err != nil {
		return err
	}
	for _, p := range skipped {
		fmt.Fprintf(a.Stderr, "kiln: skipping %s\n", p)
	}
	if _, ok := files[fn.Entrypoint]; !ok {
		return fmt.Errorf("%s not found in %s (run `kiln fn pull %s %s` first)", fn.Entrypoint, dir, fn.Site.Slug, dir)
	}

	base := meta.BaseVersionID
	res, err := c.FunctionDeploy(ctx, fn.Site.Slug, api.FunctionDeployRequest{Files: files, Message: *message, BaseVersionID: base, Force: *force})
	var conflict *api.ConflictError
	if errors.As(err, &conflict) {
		by := ""
		if conflict.Head.Author != "" {
			by = " by " + conflict.Head.Author
		}
		return exitError{ExitConflict, fmt.Errorf("v%d was deployed%s after your base; run `kiln fn pull %s` and merge, or deploy with --force", conflict.Head.Number, by, fn.Site.Slug)}
	}
	if err != nil {
		return err
	}

	paths := make([]string, 0, len(files))
	for p := range files {
		paths = append(paths, p)
	}
	sort.Strings(paths)
	if err := writeFunctionMeta(dir, functionMeta{SiteID: fn.Site.ID, Slug: fn.Site.Slug, BaseVersionID: res.Version.ID, Entrypoint: fn.Entrypoint, Files: paths}); err != nil {
		return err
	}
	for _, w := range res.Warnings {
		fmt.Fprintln(a.Stderr, "kiln: "+w)
	}

	if !*wait {
		if a.jsonOut {
			return a.printJSON(res)
		}
		if res.Created {
			fmt.Fprintf(a.Stdout, "v%d (%s) of %s deploying: deployment %s\n", res.Version.Number, res.Version.ShortHash, fn.Site.Slug, dash(res.DeploymentID))
		} else {
			fmt.Fprintf(a.Stdout, "No changes since v%d; redeploying: deployment %s\n", res.Version.Number, dash(res.DeploymentID))
		}
		return nil
	}
	if res.DeploymentID == "" {
		return errors.New("the version was saved but no deployment started")
	}
	d, err := c.Deployment(ctx, res.DeploymentID)
	if err != nil {
		return err
	}
	if !a.jsonOut {
		fmt.Fprintf(a.Stdout, "v%d (%s) of %s\n", res.Version.Number, res.Version.ShortHash, fn.Site.Slug)
	}
	return a.afterTrigger(ctx, c, "Deployment", fn.Site.Slug, d, true)
}

func cmdFnVersions(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("fn versions")
	pos, err := a.parse(fs, args, 1, 1, "<fn>")
	if err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	versions, err := c.FunctionVersions(ctx, pos[0])
	if err != nil {
		return err
	}
	if a.jsonOut {
		return a.printJSON(versions)
	}
	fn, err := c.Function(ctx, pos[0])
	if err != nil {
		return err
	}
	rows := make([][]string, 0, len(versions))
	for _, v := range versions {
		mark := ""
		if fn.Live != nil && fn.Live.ID == v.ID {
			mark = " (live)"
		}
		rows = append(rows, []string{fmt.Sprintf("v%d%s", v.Number, mark), v.ShortHash, dash(v.Author), v.CreatedAt, dash(v.Message)})
	}
	a.table([]string{"VERSION", "HASH", "AUTHOR", "CREATED", "MESSAGE"}, rows)
	return nil
}

func cmdFnRollback(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("fn rollback")
	wait := fs.Bool("wait", false, "stream output and wait for the deployment to finish")
	pos, err := a.parse(fs, args, 2, 2, "<fn> <version> [--wait]")
	if err != nil {
		return err
	}
	n, err := strconv.Atoi(strings.TrimPrefix(pos[1], "v"))
	if err != nil || n < 1 {
		return usagef("version must be a number like 3 or v3")
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	id, err := c.FunctionRollback(ctx, pos[0], n)
	if err != nil {
		return err
	}
	d, err := c.Deployment(ctx, id)
	if err != nil {
		return err
	}
	return a.afterTrigger(ctx, c, "Rollback", pos[0], d, *wait)
}

func cmdFnRun(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("fn run")
	pos, err := a.parse(fs, args, 2, 2, "<fn> <schedule>")
	if err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	run, err := c.FunctionRun(ctx, pos[0], pos[1])
	if err != nil {
		return err
	}
	out := a.Stdout
	if a.jsonOut {
		out = a.Stderr
	}
	printed, errs := 0, 0
	for {
		st, err := c.FunctionRunStatus(ctx, pos[0], run)
		if err != nil {
			if ctx.Err() != nil {
				return ctx.Err()
			}
			if errs++; errs > 5 {
				return err
			}
			fmt.Fprintf(a.Stderr, "kiln: %v (retrying)\n", err)
		} else {
			errs = 0
			if len(st.Output) > printed {
				io.WriteString(out, st.Output[printed:])
				printed = len(st.Output)
			}
			if st.Finished {
				if printed > 0 && !strings.HasSuffix(st.Output, "\n") {
					fmt.Fprintln(out)
				}
				if a.jsonOut {
					if err := a.printJSON(st); err != nil {
						return err
					}
				}
				code := 0
				if st.ExitCode != nil {
					code = *st.ExitCode
				}
				if st.Status == "succeeded" && code == 0 {
					return nil
				}
				if code == 0 {
					code = ExitError
				}
				msg := "run " + st.Status
				if st.Error != "" {
					msg += ": " + st.Error
				}
				return exitError{code, errors.New(msg)}
			}
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(a.Poll):
		}
	}
}

// headerFlags collects repeated -H 'Name: value'.
type headerFlags []string

func (h *headerFlags) String() string     { return strings.Join(*h, ", ") }
func (h *headerFlags) Set(v string) error { *h = append(*h, v); return nil }

func cmdFnInvoke(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("fn invoke")
	method := fs.String("X", "", "HTTP method (default GET, or POST with -d)")
	data := fs.String("d", "", "request body, or @file")
	var headers headerFlags
	fs.Var(&headers, "H", "request header 'Name: value' (repeatable)")
	pos, err := a.parse(fs, args, 1, 2, "<fn> [path] [-X METHOD] [-d BODY|@FILE] [-H 'K: V']…")
	if err != nil {
		return err
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	fn, err := c.Function(ctx, pos[0])
	if err != nil {
		return err
	}
	if fn.URL == "" {
		return fmt.Errorf("%s has no domain; add one in its Networking tab", fn.Site.Slug)
	}
	path := "/"
	if len(pos) == 2 {
		path = "/" + strings.TrimPrefix(pos[1], "/")
	}
	var body io.Reader
	var payload []byte
	if *data != "" {
		payload = []byte(*data)
		if strings.HasPrefix(*data, "@") {
			b, err := os.ReadFile(strings.TrimPrefix(*data, "@"))
			if err != nil {
				return err
			}
			payload = b
		}
		body = strings.NewReader(string(payload))
	}
	m := strings.ToUpper(*method)
	if m == "" {
		m = http.MethodGet
		if body != nil {
			m = http.MethodPost
		}
	}
	req, err := http.NewRequestWithContext(ctx, m, strings.TrimRight(fn.URL, "/")+path, body)
	if err != nil {
		return err
	}
	for _, h := range headers {
		k, v, ok := strings.Cut(h, ":")
		if !ok {
			return usagef("header must look like 'Name: value': %q", h)
		}
		req.Header.Set(strings.TrimSpace(k), strings.TrimSpace(v))
	}
	if body != nil && req.Header.Get("Content-Type") == "" {
		if json.Valid(payload) {
			req.Header.Set("Content-Type", "application/json")
		}
	}
	start := time.Now()
	resp, err := (&http.Client{Timeout: 2 * time.Minute}).Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	respBody, err := io.ReadAll(io.LimitReader(resp.Body, 16<<20))
	if err != nil {
		return err
	}
	elapsed := time.Since(start)
	if a.jsonOut {
		hdr := map[string]string{}
		for k := range resp.Header {
			hdr[k] = resp.Header.Get(k)
		}
		if err := a.printJSON(map[string]any{"status": resp.StatusCode, "headers": hdr, "body": string(respBody), "duration_ms": elapsed.Milliseconds()}); err != nil {
			return err
		}
	} else {
		fmt.Fprintf(a.Stdout, "%s %s (%dms)\n", resp.Proto, resp.Status, elapsed.Milliseconds())
		keys := make([]string, 0, len(resp.Header))
		for k := range resp.Header {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		for _, k := range keys {
			fmt.Fprintf(a.Stdout, "%s: %s\n", k, strings.Join(resp.Header.Values(k), ", "))
		}
		fmt.Fprintln(a.Stdout)
		a.Stdout.Write(respBody)
		if len(respBody) > 0 && respBody[len(respBody)-1] != '\n' {
			fmt.Fprintln(a.Stdout)
		}
	}
	if resp.StatusCode >= 400 {
		return exitError{ExitError, fmt.Errorf("HTTP %d", resp.StatusCode)}
	}
	return nil
}

// safeJoin joins a function file path under dir, refusing absolute paths, ".." and symlinks on the way (a
// symlinked folder in the target directory would let a file land outside it).
func safeJoin(dir, p string) (string, error) {
	clean := filepath.Clean(filepath.FromSlash(p))
	if filepath.IsAbs(clean) || clean == ".." || strings.HasPrefix(clean, ".."+string(filepath.Separator)) {
		return "", fmt.Errorf("refusing file path %q", p)
	}
	cur := dir
	for _, part := range strings.Split(clean, string(filepath.Separator)) {
		cur = filepath.Join(cur, part)
		if info, err := os.Lstat(cur); err == nil && info.Mode()&os.ModeSymlink != 0 {
			return "", fmt.Errorf("refusing file path %q: %s is a symlink", p, cur)
		}
	}
	return filepath.Join(dir, clean), nil
}

// Limits of one function's code (the control plane checks them too; these give a clear local error first).
const (
	maxFunctionFiles = 50
	maxFunctionBytes = 1 << 20
)

// ignoredDirs are never part of a function: dependencies and build output the server creates itself, and VCS.
var ignoredDirs = map[string]bool{"node_modules": true, "__pycache__": true, ".git": true, ".venv": true, "venv": true}

// readFunctionDir reads a function directory as {path: content}: every regular file, except dot-files and
// dot-folders (.kiln-function.json, .git, .env …), the folders in ignoredDirs, and the patterns of an optional
// .kilnignore (one per line: a name like "dist" or a glob like "*.log" or "tests/*"). Symlinks are skipped (a
// link could pull in files from outside the directory); non-UTF-8 (binary) files are an error.
func readFunctionDir(dir string) (map[string]string, []string, error) {
	ignore, err := readKilnIgnore(dir)
	if err != nil {
		return nil, nil, err
	}
	files, total := map[string]string{}, 0
	var skipped []string
	err = filepath.WalkDir(dir, func(p string, d os.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, err := filepath.Rel(dir, p)
		if err != nil || rel == "." {
			return err
		}
		rel = filepath.ToSlash(rel)
		name := d.Name()
		if strings.HasPrefix(name, ".") || ignored(ignore, rel, name) || (d.IsDir() && ignoredDirs[name]) {
			if d.IsDir() {
				return filepath.SkipDir
			}
			return nil
		}
		if d.Type()&os.ModeSymlink != 0 {
			skipped = append(skipped, rel+" (symlink)")
			return nil
		}
		if d.IsDir() || !d.Type().IsRegular() {
			return nil
		}
		b, err := os.ReadFile(p)
		if err != nil {
			return err
		}
		if !utf8.Valid(b) {
			return fmt.Errorf("%s is not a text file (functions hold UTF-8 source only; add it to .kilnignore)", rel)
		}
		total += len(rel) + len(b)
		if len(files) >= maxFunctionFiles || total > maxFunctionBytes {
			return fmt.Errorf("%s holds more than %d files or %d KB: a function is source code only (add the rest to .kilnignore)", dir, maxFunctionFiles, maxFunctionBytes>>10)
		}
		files[rel] = string(b)
		return nil
	})
	return files, skipped, err
}

func readKilnIgnore(dir string) ([]string, error) {
	b, err := os.ReadFile(filepath.Join(dir, ".kilnignore"))
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var out []string
	for _, line := range strings.Split(string(b), "\n") {
		if line = strings.TrimSuffix(strings.TrimSpace(line), "/"); line != "" && !strings.HasPrefix(line, "#") {
			out = append(out, strings.TrimPrefix(line, "/"))
		}
	}
	return out, nil
}

// ignored: a pattern matches the whole relative path or the file/folder name.
func ignored(patterns []string, rel, name string) bool {
	for _, pat := range patterns {
		if ok, _ := path.Match(pat, rel); ok {
			return true
		}
		if ok, _ := path.Match(pat, name); ok {
			return true
		}
	}
	return false
}

func readFunctionMeta(dir string) (functionMeta, bool, error) {
	var m functionMeta
	b, err := os.ReadFile(filepath.Join(dir, functionMetaFile))
	if errors.Is(err, os.ErrNotExist) {
		return m, false, nil
	}
	if err != nil {
		return m, false, err
	}
	if err := json.Unmarshal(b, &m); err != nil {
		return m, false, fmt.Errorf("%s: %w", functionMetaFile, err)
	}
	return m, true, nil
}

func writeFunctionMeta(dir string, m functionMeta) error {
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return err
	}
	b, _ := json.MarshalIndent(m, "", "  ")
	return os.WriteFile(filepath.Join(dir, functionMetaFile), append(b, '\n'), 0o644)
}
