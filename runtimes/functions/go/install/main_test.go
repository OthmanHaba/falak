package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestInspect(t *testing.T) {
	for name, tc := range map[string]struct {
		files map[string]string
		want  exports
		err   string
	}{
		"func handler":       {files: map[string]string{"main.go": "package main\nfunc Handler(w any, r any) {}\n"}, want: exports{handler: true}},
		"var handler + cron": {files: map[string]string{"main.go": "package main\nvar Handler = 1\n", "jobs.go": "package main\nfunc Scheduled() {}\n"}, want: exports{handler: true, scheduled: true}},
		"scheduled only":     {files: map[string]string{"main.go": "package main\nfunc Scheduled() {}\n"}, want: exports{scheduled: true}},
		"method is not it":   {files: map[string]string{"main.go": "package main\ntype T int\nfunc (T) Handler() {}\n"}, err: "export a Handler"},
		"own main":           {files: map[string]string{"main.go": "package main\nfunc main() {}\nfunc Handler() {}\n"}, err: "declares main()"},
		"reserved Event":     {files: map[string]string{"main.go": "package main\ntype Event struct{}\nfunc Handler() {}\n"}, err: "reserved"},
		"reserved kiln":      {files: map[string]string{"main.go": "package main\nvar kilnX = 1\nfunc Handler() {}\n"}, err: "reserved"},
		"other package":      {files: map[string]string{"main.go": "package handler\nfunc Handler() {}\n"}, err: "must be package main"},
		"reserved file":      {files: map[string]string{"main.go": "package main\nfunc Handler() {}\n", "kiln_main.go": "package main\n"}, err: "reserved"},
		"tests are ignored":  {files: map[string]string{"main.go": "package main\nfunc Handler() {}\n", "x_test.go": "package other\n"}, want: exports{handler: true}},
	} {
		t.Run(name, func(t *testing.T) {
			dir := t.TempDir()
			for p, content := range tc.files {
				if err := os.WriteFile(filepath.Join(dir, p), []byte(content), 0o644); err != nil {
					t.Fatal(err)
				}
			}
			got, err := inspect(dir, "main.go")
			if tc.err != "" {
				if err == nil || !strings.Contains(err.Error(), tc.err) {
					t.Fatalf("err = %v, want %q", err, tc.err)
				}
				return
			}
			if err != nil || got != tc.want {
				t.Fatalf("got %+v, %v; want %+v", got, err, tc.want)
			}
		})
	}
}

func TestGeneratedMain(t *testing.T) {
	if got := generatedMain(exports{handler: true}); !strings.Contains(got, "kilnMain(kilnAdapt(Handler), nil)") {
		t.Fatal(got)
	}
	if got := generatedMain(exports{scheduled: true}); !strings.Contains(got, "kilnMain(nil, Scheduled)") {
		t.Fatal(got)
	}
}
