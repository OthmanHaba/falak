package deploy

import (
	"context"
	"encoding/json"
	"io"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

func TestPrepareMovesAnOldEnvFileToTheTmpfs(t *testing.T) {
	d, _, _, srv, arts := newDeployer(t)
	root := d.o.FS.P("/srv/falak/sites/shop")
	fetch(t, d, srv, arts, r1, "v1")
	// An earlier agent kept .env as a regular file in shared/.
	os.MkdirAll(filepath.Join(root, "shared"), 0o750)
	os.WriteFile(filepath.Join(root, "shared/.env"), []byte("APP_KEY=old-secret\n"), 0o640)

	if _, err := d.Prepare(context.Background(), PreparePayload{Site: "shop", ReleaseID: r1, EnvFile: &EnvFile{Content: "APP_KEY=new-secret\n"}}, st()); err != nil {
		t.Fatal(err)
	}
	fi, err := os.Lstat(filepath.Join(root, "shared/.env"))
	if err != nil || fi.Mode()&os.ModeSymlink == 0 {
		t.Fatalf("shared/.env is not a symlink: %v %v", fi, err)
	}
	if b, _ := os.ReadFile(filepath.Join(root, "releases", r1, ".env")); string(b) != "APP_KEY=new-secret\n" {
		t.Fatalf("release .env %q", b)
	}
	// Nothing with a secret is left in the site's tree (on disk).
	filepath.WalkDir(root, func(p string, e os.DirEntry, err error) error {
		if err == nil && e.Type().IsRegular() {
			if b, _ := os.ReadFile(p); strings.Contains(string(b), "secret") {
				t.Errorf("%s holds a secret on disk", p)
			}
		}
		return nil
	})
}

func TestEnvFileRehydrationAfterReboot(t *testing.T) {
	d, _, _, srv, arts := newDeployer(t)
	root := d.o.FS.P("/srv/falak/sites/shop")
	fetch(t, d, srv, arts, r1, "v1")
	if _, err := d.Prepare(context.Background(), PreparePayload{Site: "shop", ReleaseID: r1, EnvFile: &EnvFile{Content: "APP_KEY=secret-1\n"}}, st()); err != nil {
		t.Fatal(err)
	}
	// A site that was never prepared and a stray directory are not reported.
	os.MkdirAll(d.o.FS.P("/srv/falak/sites/blog/shared"), 0o750)
	os.MkdirAll(d.o.FS.P("/srv/falak/sites/Not_A_Site"), 0o750)
	if got := d.MissingSecrets(context.Background()); len(got) != 0 {
		t.Fatalf("nothing is missing yet: %v", got)
	}

	// A reboot empties /run.
	os.RemoveAll(d.o.FS.P("/run/falak"))
	if got := d.MissingSecrets(context.Background()); !slices.Equal(got, []string{"shop"}) {
		t.Fatalf("missing = %v", got)
	}

	c := &commands.Collector{}
	res, err := d.WriteEnv(context.Background(), EnvWritePayload{Site: "shop", EnvFile: &EnvFile{Content: "APP_KEY=secret-1\n"}}, commands.NewTestStream("w", c))
	if err != nil {
		t.Fatal(err)
	}
	if !res.(EnvWriteResult).Changed {
		t.Fatal("site.env.write should report a change")
	}
	if b, _ := os.ReadFile(filepath.Join(root, "releases", r1, ".env")); string(b) != "APP_KEY=secret-1\n" {
		t.Fatalf("release .env %q", b)
	}
	if got := d.MissingSecrets(context.Background()); len(got) != 0 {
		t.Fatalf("still missing: %v", got)
	}
	if fi, _ := os.Stat(d.o.FS.P("/run/falak/env")); fi.Mode().Perm() != 0o711 {
		t.Fatalf("env dir mode %v", fi.Mode().Perm())
	}
	// Idempotent.
	if again, _ := d.WriteEnv(context.Background(), EnvWritePayload{Site: "shop", EnvFile: &EnvFile{Content: "APP_KEY=secret-1\n"}}, st()); again.(EnvWriteResult).Changed {
		t.Fatal("second write should be unchanged")
	}
}

type fakeContainers struct {
	missing  []string
	restored map[string]map[string]string
}

func (f *fakeContainers) MissingSecrets(context.Context) ([]string, error) { return f.missing, nil }

func (f *fakeContainers) RestoreSiteSecrets(_ context.Context, site string, files map[string]string, _ io.Writer) ([]string, error) {
	if f.restored == nil {
		f.restored = map[string]map[string]string{}
	}
	f.restored[site] = files
	return []string{"falak-" + site + "-blue"}, nil
}

func TestWriteEnvRestoresContainerSecrets(t *testing.T) {
	d, _, _, _, _ := newDeployer(t)
	fc := &fakeContainers{missing: []string{"api", "shop"}}
	d.o.Containers = fc
	if got := d.MissingSecrets(context.Background()); !slices.Equal(got, []string{"api", "shop"}) {
		t.Fatalf("missing = %v", got)
	}
	res, err := d.WriteEnv(context.Background(), EnvWritePayload{Site: "api", SecretFiles: []SecretFile{{Name: "DB_PASSWORD", Content: "pw-123456"}}}, st())
	if err != nil {
		t.Fatal(err)
	}
	if r := res.(EnvWriteResult); !r.Changed || !slices.Equal(r.Containers, []string{"falak-api-blue"}) || fc.restored["api"]["DB_PASSWORD"] != "pw-123456" {
		t.Fatalf("%+v %v", r, fc.restored)
	}
}

func TestEnvWritePayloadSecrets(t *testing.T) {
	var p EnvWritePayload
	json.Unmarshal([]byte(`{"site":"shop","env_file":{"content":"APP_KEY=base64:abcdef\nAPP_NAME=Shop\n"},"secret_files":[{"name":"X","content":"file-secret"}],"mask":["APP_KEY"]}`), &p)
	if got := p.Secrets(); !slices.Equal(got, []string{"base64:abcdef", "file-secret"}) {
		t.Fatalf("secrets = %v", got)
	}
}

func TestHookOutputMasksTheSiteEnvFile(t *testing.T) {
	d, fake, _, srv, arts := newDeployer(t)
	fetch(t, d, srv, arts, r1, "v1")
	if _, err := d.Prepare(context.Background(), PreparePayload{Site: "shop", ReleaseID: r1, EnvFile: &EnvFile{Content: "APP_NAME=Shop\nDB_PASSWORD=\"from-env-file\"\n"}}, st()); err != nil {
		t.Fatal(err)
	}
	fake.On("/bin/bash", runner.Result{ExitCode: 0, Stdout: []byte("config: DB_PASSWORD=from-env-file TOKEN=from-payload APP_NAME=Shop\n")})
	reg := commands.NewRegistry()
	d.Register(reg)
	c := &commands.Collector{}
	disp := commands.NewDispatcher(context.Background(), reg, c, nil)
	payload := `{"site":"shop","release_id":"` + r1 + `","name":"cfg","script":"php artisan config:show","env":{"TOKEN":"from-payload"},"mask":["DB_PASSWORD","TOKEN"]}`
	disp.Submit(commands.Envelope{ID: "h1", Type: "deploy.hook", TimeoutS: 30, Payload: json.RawMessage(payload)})
	disp.Wait()
	out := c.Output("stdout")
	if strings.Contains(out, "from-env-file") || strings.Contains(out, "from-payload") || !strings.Contains(out, "DB_PASSWORD=•••• TOKEN=•••• APP_NAME=Shop") {
		t.Fatalf("output %q", out)
	}
}
