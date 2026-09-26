package agent

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/kiln/agent/internal/config"
	"github.com/kiln/agent/internal/hostfs"
	"github.com/kiln/agent/internal/runner/runnertest"
)

func TestInstall(t *testing.T) {
	root := t.TempDir()
	src := filepath.Join(t.TempDir(), "kiln-agent")
	os.WriteFile(src, []byte("binary"), 0o755)
	cfg := config.Default()
	cfg.PanelURL, cfg.Token = "https://panel.example", "tok"
	fake := &runnertest.Fake{}
	fs := hostfs.FS{Root: root}
	if err := Install(context.Background(), InstallOptions{Config: cfg, Source: src, FS: fs, Runner: fake}); err != nil {
		t.Fatal(err)
	}
	unit, _ := fs.ReadFile(UnitPath)
	if !strings.Contains(string(unit), "ExecStart=/usr/local/bin/kiln-agent run") {
		t.Fatal(string(unit))
	}
	env, _ := fs.ReadFile("/etc/kiln/agent.env")
	if string(env) != "KILN_PANEL_URL=https://panel.example\nKILN_TOKEN=tok\n" {
		t.Fatalf("env %q", env)
	}
	if st, _ := os.Stat(fs.P("/etc/kiln/agent.env")); st.Mode().Perm() != 0o600 {
		t.Fatal("env file must be 0600")
	}
	if b, _ := fs.ReadFile(BinaryPath); string(b) != "binary" {
		t.Fatal("binary not copied")
	}
	want := []string{"systemctl daemon-reload", "systemctl enable --now kiln-agent.service", "systemctl restart kiln-agent.service"}
	if got := fake.Lines(); strings.Join(got, "|") != strings.Join(want, "|") {
		t.Fatalf("got %v", got)
	}
	fake.Reset()
	if err := Install(context.Background(), InstallOptions{Config: cfg, Source: src, FS: fs, Runner: fake, NoStart: true}); err != nil {
		t.Fatal(err)
	}
	if fake.Ran("systemctl daemon-reload") {
		t.Fatal("unchanged unit should not trigger daemon-reload")
	}
}
