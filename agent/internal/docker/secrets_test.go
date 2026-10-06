package docker

import (
	"bytes"
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
)

// unlock makes the read-only secret directories under root removable again (tests don't run as root).
func unlock(root string) {
	filepath.WalkDir(root, func(p string, d os.DirEntry, err error) error {
		if err == nil && d.IsDir() {
			os.Chmod(p, 0o700)
		}
		return nil
	})
}

func secretsSvc(t *testing.T) (*Service, *fakeEngine, string) {
	s, e, _, _, root := newSvc(t)
	t.Cleanup(func() { unlock(root) })
	return s, e, root
}

func TestSwapMountsSecretFilesInsteadOfEnv(t *testing.T) {
	s, e, root := secretsSvc(t)
	ok := 200
	blue, green := healthServer(t, &ok), healthServer(t, &ok)
	p := swapPayload(blue, green)
	p.Env["DB_PASSWORD"] = "leaks-if-in-env"
	p.SecretFiles = []SecretFile{{Name: "DB_PASSWORD", Content: "db-secret-123"}, {Name: "APP_KEY", Content: "base64:appkey"}}

	fin, col := exec1(t, s, "deploy.container.swap", p)
	if fin.Error != "" {
		t.Fatal(fin.Error, col.Output(""))
	}
	c := e.byName("falak-shop-blue")
	for _, kv := range c.body.Env {
		if strings.HasPrefix(kv, "DB_PASSWORD=") {
			t.Fatalf("secret passed as env: %v", c.body.Env)
		}
	}
	dir := filepath.Join(root, "run/falak/secrets/falak-shop-blue")
	if m := c.body.HostConfig.Mounts; len(m) != 1 || m[0] != (Mount{Type: "bind", Source: dir, Target: "/run/secrets", ReadOnly: true}) {
		t.Fatalf("mounts %+v", m)
	}
	if c.body.Labels[LabelSecrets] != "files" || len(c.body.HostConfig.Binds) != 0 {
		t.Fatalf("labels %v binds %v", c.body.Labels, c.body.HostConfig.Binds)
	}
	if b, _ := os.ReadFile(filepath.Join(dir, "DB_PASSWORD")); string(b) != "db-secret-123" {
		t.Fatalf("secret file %q", b)
	}
	if fi, _ := os.Stat(filepath.Join(dir, "APP_KEY")); fi.Mode().Perm() != 0o444 {
		t.Fatalf("file mode %v", fi.Mode().Perm())
	}
	if fi, _ := os.Stat(filepath.Join(root, "run/falak/secrets")); fi.Mode().Perm() != 0o700 {
		t.Fatalf("parent mode %v", fi.Mode().Perm())
	}

	// The next deploy goes to green; blue's files go with blue.
	p.Image = "registry.local/shop:def"
	if fin, _ := exec1(t, s, "deploy.container.swap", p); fin.Error != "" {
		t.Fatal(fin.Error)
	}
	if _, err := os.Stat(dir); !os.IsNotExist(err) {
		t.Fatal("retired container's secrets left behind")
	}
	if _, err := os.Stat(filepath.Join(root, "run/falak/secrets/falak-shop-green/DB_PASSWORD")); err != nil {
		t.Fatal(err)
	}
}

func TestRunSecretFilesOwnedByANumericUser(t *testing.T) {
	s, e, root := secretsSvc(t)
	e.images["redis:7"] = "sha256:r"
	p := RunPayload{Name: "cache", Image: "redis:7", User: "999", SecretFiles: []SecretFile{{Name: "REDIS_PASSWORD", Content: "redis-pw-1"}}}
	if fin, col := exec1(t, s, "docker.run", p); fin.Error != "" {
		t.Fatal(fin.Error, col.Output(""))
	}
	dir := filepath.Join(root, "run/falak/secrets/cache")
	if fi, _ := os.Stat(filepath.Join(dir, "REDIS_PASSWORD")); fi.Mode().Perm() != 0o400 {
		t.Fatalf("file mode %v", fi.Mode().Perm())
	}
	if fi, _ := os.Stat(dir); fi.Mode().Perm() != 0o500 {
		t.Fatalf("dir mode %v", fi.Mode().Perm())
	}
	if e.byName("cache").body.Labels[LabelSecretsOwner] != "999:999" {
		t.Fatal("owner label missing")
	}
	// Removing the container removes its files.
	if fin, _ := exec1(t, s, "docker.stop", StopPayload{Name: "cache", Remove: true}); fin.Error != "" {
		t.Fatal(fin.Error)
	}
	if _, err := os.Stat(dir); !os.IsNotExist(err) {
		t.Fatal("secrets left after remove")
	}
}

func TestMissingAndRestoredSecretsAfterReboot(t *testing.T) {
	s, e, root := secretsSvc(t)
	ok := 200
	p := swapPayload(healthServer(t, &ok), healthServer(t, &ok))
	p.SecretFiles = []SecretFile{{Name: "DB_PASSWORD", Content: "db-secret-123"}}
	if fin, _ := exec1(t, s, "deploy.container.swap", p); fin.Error != "" {
		t.Fatal(fin.Error)
	}
	// A container without secret files is never reported.
	e.images["redis:7"] = "sha256:r"
	exec1(t, s, "docker.run", RunPayload{Name: "plain", Image: "redis:7", Labels: map[string]string{LabelSite: "other"}})
	ctx := context.Background()
	if got, _ := s.MissingSecrets(ctx); len(got) != 0 {
		t.Fatalf("missing = %v", got)
	}

	// Reboot: /run is empty, Docker could not start the container.
	unlock(root)
	os.RemoveAll(filepath.Join(root, "run"))
	e.byName("falak-shop-blue").running = false
	if got, _ := s.MissingSecrets(ctx); !slices.Equal(got, []string{"shop"}) {
		t.Fatalf("missing = %v", got)
	}
	var out bytes.Buffer
	names, err := s.RestoreSiteSecrets(ctx, "shop", map[string]string{"DB_PASSWORD": "db-secret-123"}, &out)
	if err != nil || !slices.Equal(names, []string{"falak-shop-blue"}) {
		t.Fatalf("%v %v", names, err)
	}
	if !e.byName("falak-shop-blue").running {
		t.Fatal("container not started")
	}
	if b, _ := os.ReadFile(filepath.Join(root, "run/falak/secrets/falak-shop-blue/DB_PASSWORD")); string(b) != "db-secret-123" {
		t.Fatalf("file %q", b)
	}
	if got, _ := s.MissingSecrets(ctx); len(got) != 0 {
		t.Fatalf("still missing: %v", got)
	}
	// A container stopped on purpose (its files are there) stays stopped.
	e.byName("falak-shop-blue").running = false
	s.RestoreSiteSecrets(ctx, "shop", map[string]string{"DB_PASSWORD": "db-secret-123"}, &out)
	if e.byName("falak-shop-blue").running {
		t.Fatal("a stopped container was started")
	}
}

func TestSwapMasksSecretsInOutput(t *testing.T) {
	var p SwapPayload
	json.Unmarshal([]byte(`{"env":{"DB_PASSWORD":"env-secret","APP_ENV":"production"},"mask":["DB_PASSWORD"],"secret_files":[{"name":"API_TOKEN","content":"file-secret"}]}`), &p)
	if got := p.Secrets(); !slices.Equal(got, []string{"env-secret", "file-secret"}) {
		t.Fatalf("secrets = %v", got)
	}
}

func TestInvalidSecretFileName(t *testing.T) {
	s, e, _ := secretsSvc(t)
	e.images["redis:7"] = "sha256:r"
	fin, _ := exec1(t, s, "docker.run", RunPayload{Name: "bad", Image: "redis:7", SecretFiles: []SecretFile{{Name: "../etc/passwd", Content: "x"}}})
	if !strings.Contains(fin.Error, "invalid secret file name") {
		t.Fatalf("error %q", fin.Error)
	}
}
