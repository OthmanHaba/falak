package facts

import (
	"context"
	"encoding/json"
	"net"
	"os"
	"path/filepath"
	"testing"

	"github.com/kiln/agent/internal/hostfs"
	"github.com/kiln/agent/internal/runner"
	"github.com/kiln/agent/internal/runner/runnertest"
)

func write(t *testing.T, root, p, s string) {
	t.Helper()
	full := filepath.Join(root, p)
	os.MkdirAll(filepath.Dir(full), 0o755)
	if err := os.WriteFile(full, []byte(s), 0o644); err != nil {
		t.Fatal(err)
	}
}

func TestCollect(t *testing.T) {
	root := t.TempDir()
	write(t, root, "/etc/hostname", "web-1\n")
	write(t, root, "/etc/os-release", "NAME=\"Ubuntu\"\nID=ubuntu\nVERSION_ID=\"24.04\"\n")
	write(t, root, "/proc/sys/kernel/osrelease", "6.8.0-45-generic\n")
	write(t, root, "/proc/meminfo", "MemTotal:        4028440 kB\nMemFree: 1 kB\n")
	os.MkdirAll(filepath.Join(root, "/etc/php/8.4"), 0o755)
	os.MkdirAll(filepath.Join(root, "/etc/php/8.3"), 0o755)
	os.MkdirAll(filepath.Join(root, "/etc/php/mods-available"), 0o755)
	os.MkdirAll(filepath.Join(root, "/opt/kiln/node/22.11.0"), 0o755)
	old := Interfaces
	Interfaces = func() ([]net.Addr, error) {
		return []net.Addr{
			&net.IPNet{IP: net.ParseIP("127.0.0.1"), Mask: net.CIDRMask(8, 32)},
			&net.IPNet{IP: net.ParseIP("10.0.0.5"), Mask: net.CIDRMask(24, 32)},
			&net.IPNet{IP: net.ParseIP("203.0.113.9"), Mask: net.CIDRMask(24, 32)},
		}, nil
	}
	defer func() { Interfaces = old }()
	fr := (&runnertest.Fake{}).On("docker version", runner.Result{Stdout: []byte("27.3.1\n")})
	f, err := Collect(context.Background(), fr, hostfs.FS{Root: root}, "v1.0.0")
	if err != nil {
		t.Fatal(err)
	}
	if f.Hostname != "web-1" || f.OS.ID != "ubuntu" || f.OS.Version != "24.04" || f.Kernel != "6.8.0-45-generic" {
		t.Fatalf("%+v", f)
	}
	if f.MemoryBytes != 4028440*1024 || f.DiskBytes <= 0 || f.CPUs < 1 {
		t.Fatalf("%+v", f)
	}
	if *f.PublicIPv4 != "203.0.113.9" || *f.PrivateIPv4 != "10.0.0.5" || *f.Docker != "27.3.1" {
		t.Fatalf("%+v", f)
	}
	if len(f.Runtimes["php"]) != 2 || f.Runtimes["php"][1] != "8.4" || f.Runtimes["node"][0] != "22.11.0" {
		t.Fatalf("%+v", f.Runtimes)
	}
	b, _ := json.Marshal(f)
	var m map[string]any
	json.Unmarshal(b, &m)
	for _, k := range []string{"hostname", "os", "arch", "cpus", "memory_bytes", "disk_bytes", "agent_version"} {
		if _, ok := m[k]; !ok {
			t.Fatalf("missing %s", k)
		}
	}
}

func TestDockerAbsentIsNull(t *testing.T) {
	fr := (&runnertest.Fake{}).On("docker", runner.Result{ExitCode: 127})
	f, _ := Collect(context.Background(), fr, hostfs.FS{Root: t.TempDir()}, "dev")
	b, _ := json.Marshal(f)
	var m map[string]any
	json.Unmarshal(b, &m)
	if v, ok := m["docker"]; !ok || v != nil {
		t.Fatalf("docker should be null: %s", b)
	}
}
