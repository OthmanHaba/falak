// kiln-fn-install for Go functions: checks the function's package, resolves its modules and builds one static
// binary into /app/.kiln/fn (served by kiln-fn-serve, run by kiln-fn-run).
//
// The function is a `package main` (the entrypoint's folder) that exports
//
//	func Handler(w http.ResponseWriter, r *http.Request)   // or: var Handler http.Handler = mux
//	func Scheduled(ctx context.Context, event Event) error  // optional, for schedules
//
// and has no main(): the build adds the runtime (kiln_runtime.go: server, telemetry, Event) and a generated main.
// It builds in a copy under /tmp, so the release only gains go.mod / go.sum (kept by the agent per code version, so
// the same code always builds with the same module versions) and .kiln/fn.
package main

import (
	"errors"
	"fmt"
	"go/ast"
	"go/parser"
	"go/token"
	"io/fs"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"
)

const (
	app     = "/app"
	runtime = "/opt/kiln-fn/go/kiln_runtime.go"
	build   = "/tmp/kiln-build"
	binary  = ".kiln/fn"
)

func main() {
	if err := install(); err != nil {
		fmt.Fprintln(os.Stderr, "kiln:", err)
		os.Exit(1)
	}
}

func install() error {
	entry := os.Getenv("KILN_ENTRYPOINT")
	if entry == "" {
		entry = "main.go"
	}
	if _, err := os.Stat(filepath.Join(app, entry)); err != nil {
		return fmt.Errorf("entrypoint %s not found", entry)
	}
	pkgDir := filepath.Dir(entry)
	exports, err := inspect(filepath.Join(app, pkgDir), entry)
	if err != nil {
		return err
	}

	_ = os.RemoveAll(build)
	if err := copyTree(app, build); err != nil {
		return err
	}
	if _, err := os.Stat(filepath.Join(build, "go.mod")); errors.Is(err, fs.ErrNotExist) {
		if err := gocmd(build, "mod", "init", "function"); err != nil {
			return err
		}
	}
	rt, err := os.ReadFile(runtime)
	if err != nil {
		return err
	}
	if err := os.WriteFile(filepath.Join(build, pkgDir, "kiln_runtime.go"), rt, 0o644); err != nil {
		return err
	}
	if err := os.WriteFile(filepath.Join(build, pkgDir, "kiln_main.go"), []byte(generatedMain(exports)), 0o644); err != nil {
		return err
	}

	fmt.Println("resolving modules")
	if err := gocmd(build, "mod", "tidy"); err != nil {
		return errors.New("go mod tidy failed (see above)")
	}
	for _, name := range []string{"go.mod", "go.sum"} {
		b, err := os.ReadFile(filepath.Join(build, name))
		if errors.Is(err, fs.ErrNotExist) {
			continue
		}
		if err != nil {
			return err
		}
		if err := os.WriteFile(filepath.Join(app, name), b, 0o644); err != nil {
			return err
		}
	}

	fmt.Println("building")
	started := time.Now()
	out := filepath.Join(app, binary)
	if err := os.MkdirAll(filepath.Dir(out), 0o755); err != nil {
		return err
	}
	if err := gocmd(build, "build", "-trimpath", "-ldflags=-s -w", "-o", out, "./"+filepath.ToSlash(pkgDir)); err != nil {
		return errors.New("the build failed (see above)")
	}
	info, err := os.Stat(out)
	if err != nil {
		return err
	}
	fmt.Printf("built %s (%.1f MB) in %s\n", binary, float64(info.Size())/(1<<20), time.Since(started).Round(100*time.Millisecond))
	_ = os.RemoveAll(build)
	return nil
}

type exports struct{ handler, scheduled bool }

// inspect checks the function's package: package main, Handler and/or Scheduled, no main() and nothing named like
// the runtime's own identifiers.
func inspect(dir, entry string) (exports, error) {
	var ex exports
	fset := token.NewFileSet()
	entries, err := os.ReadDir(dir)
	if err != nil {
		return ex, err
	}
	for _, e := range entries {
		name := e.Name()
		if e.IsDir() || !strings.HasSuffix(name, ".go") || strings.HasSuffix(name, "_test.go") {
			continue
		}
		if name == "kiln_runtime.go" || name == "kiln_main.go" {
			return ex, fmt.Errorf("%s is reserved for the Kiln runtime: rename your file", name)
		}
		file, err := parser.ParseFile(fset, filepath.Join(dir, name), nil, parser.SkipObjectResolution)
		if err != nil {
			return ex, err
		}
		if file.Name.Name != "main" {
			return ex, fmt.Errorf("%s is package %s: the function's files next to %s must be package main", name, file.Name.Name, entry)
		}
		for _, decl := range file.Decls {
			for _, id := range topLevel(decl) {
				switch {
				case id == "main":
					return ex, fmt.Errorf("%s declares main(): remove it, Kiln generates it (export Handler and/or Scheduled instead)", name)
				case id == "Event" || strings.HasPrefix(id, "kiln"):
					return ex, fmt.Errorf("%s declares %s: Event and names starting with \"kiln\" are reserved for the Kiln runtime", name, id)
				case id == "Handler":
					ex.handler = true
				case id == "Scheduled":
					ex.scheduled = true
				}
			}
		}
	}
	if !ex.handler && !ex.scheduled {
		return ex, errors.New("export a Handler (func Handler(w http.ResponseWriter, r *http.Request), or var Handler http.Handler) and/or func Scheduled(ctx context.Context, event Event) error")
	}
	return ex, nil
}

// topLevel names the functions (not methods), variables, constants and types a declaration adds to the package.
func topLevel(decl ast.Decl) []string {
	var out []string
	switch d := decl.(type) {
	case *ast.FuncDecl:
		if d.Recv == nil {
			out = append(out, d.Name.Name)
		}
	case *ast.GenDecl:
		for _, spec := range d.Specs {
			switch s := spec.(type) {
			case *ast.ValueSpec:
				for _, n := range s.Names {
					out = append(out, n.Name)
				}
			case *ast.TypeSpec:
				out = append(out, s.Name.Name)
			}
		}
	}
	return out
}

func generatedMain(ex exports) string {
	handler, scheduled := "nil", "nil"
	if ex.handler {
		handler = "kilnAdapt(Handler)"
	}
	if ex.scheduled {
		scheduled = "Scheduled"
	}
	return "// Code generated by Kiln. DO NOT EDIT.\n\npackage main\n\nfunc main() { kilnMain(" + handler + ", " + scheduled + ") }\n"
}

// copyTree copies the function's files (not dot entries: .kiln, the release marker) into dst.
func copyTree(src, dst string) error {
	return filepath.WalkDir(src, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, _ := filepath.Rel(src, p)
		if rel != "." && strings.HasPrefix(d.Name(), ".") {
			if d.IsDir() {
				return filepath.SkipDir
			}
			return nil
		}
		target := filepath.Join(dst, rel)
		if d.IsDir() {
			return os.MkdirAll(target, 0o755)
		}
		if !d.Type().IsRegular() {
			return nil
		}
		b, err := os.ReadFile(p)
		if err != nil {
			return err
		}
		return os.WriteFile(target, b, 0o644)
	})
}

func gocmd(dir string, args ...string) error {
	cmd := exec.Command("go", args...)
	cmd.Dir = dir
	cmd.Stdout, cmd.Stderr = os.Stdout, os.Stderr
	return cmd.Run()
}
