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
	"github.com/OthmanHaba/falak/agent/internal/envlinks"
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
	d, fake, pr, srv, arts := newDeployer(t)
	ctx := context.Background()
	root := d.o.FS.P("/srv/falak/sites/shop")
	fetch(t, d, srv, arts, r1, "v1")
	if _, err := d.Prepare(ctx, PreparePayload{Site: "shop", ReleaseID: r1, EnvFile: &EnvFile{Content: "APP_KEY=secret-1\n"}, Owner: &Owner{User: "shop"}}, st()); err != nil {
		t.Fatal(err)
	}
	if _, err := d.Activate(ctx, ActivatePayload{Site: "shop", ReleaseID: r1}, st()); err != nil {
		t.Fatal(err)
	}
	// The site's tmpfs cache directory (Laravel's APP_CONFIG_CACHE) exists next to the env file.
	if fi, err := os.Stat(d.o.FS.P("/run/falak/env/shop.d")); err != nil || !fi.IsDir() {
		t.Fatalf("cache dir: %v", err)
	}
	// A site that was never prepared and a stray directory are not reported.
	os.MkdirAll(d.o.FS.P("/srv/falak/sites/blog/shared"), 0o750)
	os.MkdirAll(d.o.FS.P("/srv/falak/sites/Not_A_Site"), 0o750)
	if got := d.MissingSecrets(ctx); len(got) != 0 {
		t.Fatalf("nothing is missing yet: %v", got)
	}

	// A reboot empties /run.
	os.RemoveAll(d.o.FS.P("/run/falak"))
	if got := d.MissingSecrets(ctx); !slices.Equal(got, []string{"shop"}) {
		t.Fatalf("missing = %v", got)
	}

	// A write for a release that is not current is refused (a deployment won the race).
	if _, err := d.WriteEnv(ctx, EnvWritePayload{Site: "shop", ReleaseID: r2, EnvFile: &EnvFile{Content: "APP_KEY=old\n"}}, st()); err == nil || !strings.Contains(err.Error(), "not current") {
		t.Fatalf("stale release accepted: %v", err)
	}

	fake.On("/bin/bash", runner.Result{ExitCode: 0})
	c := &commands.Collector{}
	res, err := d.WriteEnv(ctx, EnvWritePayload{Site: "shop", ReleaseID: r1, EnvFile: &EnvFile{Content: "APP_KEY=secret-1\n"},
		After: &AfterWrite{Script: "php artisan config:cache", User: "shop"}, Reload: []Reload{{Kind: "site_procs", Name: "shop"}}}, commands.NewTestStream("w", c))
	if err != nil {
		t.Fatal(err)
	}
	if !res.(EnvWriteResult).Changed {
		t.Fatal("site.env.write should report a change")
	}
	if b, _ := os.ReadFile(filepath.Join(root, "current", ".env")); string(b) != "APP_KEY=secret-1\n" {
		t.Fatalf("current .env %q", b)
	}
	if calls := fake.Calls(); len(calls) != 1 || calls[0].User != "shop" || !strings.Contains(strings.Join(calls[0].Args, " "), "config:cache") || calls[0].Dir != filepath.Join(root, "current") {
		t.Fatalf("after script: %+v", calls)
	}
	if !slices.ContainsFunc(pr.names, func(n []string) bool { return slices.Equal(n, []string{"site:shop"}) }) {
		t.Fatalf("site programs not restarted: %v", pr.names)
	}
	if got := d.MissingSecrets(ctx); len(got) != 0 {
		t.Fatalf("still missing: %v", got)
	}
	if fi, _ := os.Stat(d.o.FS.P("/run/falak/env")); fi.Mode().Perm() != 0o711 {
		t.Fatalf("env dir mode %v", fi.Mode().Perm())
	}
	// A file that exists is newer (a deployment wrote it): never overwritten by a restore.
	os.Chmod(d.o.FS.P("/run/falak/env/shop.env"), 0o640)
	os.WriteFile(d.o.FS.P("/run/falak/env/shop.env"), []byte("APP_KEY=from-a-deploy\n"), 0o440)
	if again, _ := d.WriteEnv(ctx, EnvWritePayload{Site: "shop", ReleaseID: r1, EnvFile: &EnvFile{Content: "APP_KEY=secret-1\n"}}, st()); again.(EnvWriteResult).Changed {
		t.Fatal("an existing env file was rewritten")
	}
	if b, _ := os.ReadFile(d.o.FS.P("/run/falak/env/shop.env")); string(b) != "APP_KEY=from-a-deploy\n" {
		t.Fatalf("env file %q", b)
	}
}

func TestEnvLinksInACustomSitesRoot(t *testing.T) {
	d, _, _, srv, arts := newDeployer(t)
	d.o.Links = envlinks.New(filepath.Join(t.TempDir(), "links.json"))
	ctx := context.Background()
	// Fetch into the default root, then move the site under a custom root the default scan never sees.
	fetch(t, d, srv, arts, r1, "v1")
	os.MkdirAll(d.o.FS.P("/data/sites"), 0o755)
	os.Rename(d.o.FS.P("/srv/falak/sites/shop"), d.o.FS.P("/data/sites/shop"))
	if _, err := d.Prepare(ctx, PreparePayload{Site: "shop", ReleaseID: r1, SitesRoot: "/data/sites", EnvFile: &EnvFile{Content: "APP_KEY=secret-1\n"}}, st()); err != nil {
		t.Fatal(err)
	}
	os.RemoveAll(d.o.FS.P("/run/falak"))
	if got := d.MissingSecrets(ctx); !slices.Equal(got, []string{"shop"}) {
		t.Fatalf("missing = %v", got)
	}
}

func TestEnvLinkRefusesSymlinksPlantedBySiteUser(t *testing.T) {
	d, _, _, srv, arts := newDeployer(t)
	ctx := context.Background()
	root := d.o.FS.P("/srv/falak/sites/shop")
	fetch(t, d, srv, arts, r1, "v1")
	outside := t.TempDir()
	os.Chmod(outside, 0o700)

	// shared/ itself replaced by a link out of the site.
	os.RemoveAll(filepath.Join(root, "shared"))
	os.Symlink(outside, filepath.Join(root, "shared"))
	if _, err := d.Prepare(ctx, PreparePayload{Site: "shop", ReleaseID: r1, SharedPaths: &[]SharedPath{}, EnvFile: &EnvFile{Content: "APP_KEY=secret-1\n"}}, st()); err == nil {
		t.Fatal("env link written through a symlinked shared/")
	}
	if fi, _ := os.Stat(outside); fi.Mode().Perm() != 0o700 {
		t.Fatalf("the link target's mode was changed: %v", fi.Mode().Perm())
	}
	// A symlinked directory on the env path inside shared/.
	os.Remove(filepath.Join(root, "shared"))
	os.MkdirAll(filepath.Join(root, "shared"), 0o750)
	os.Symlink(outside, filepath.Join(root, "shared", "config"))
	if _, err := d.Prepare(ctx, PreparePayload{Site: "shop", ReleaseID: r1, SharedPaths: &[]SharedPath{}, EnvFile: &EnvFile{Content: "APP_KEY=secret-1\n", Path: "config/.env"}}, st()); err == nil || !strings.Contains(err.Error(), "symlink") {
		t.Fatalf("env link written through a symlinked directory: %v", err)
	}
	if ents, _ := os.ReadDir(outside); len(ents) != 0 {
		t.Fatalf("wrote outside the site: %v", ents)
	}
}

func TestWriteEnvRestoresAComposeEnvFile(t *testing.T) {
	d, _, _, srv, arts := newDeployer(t)
	ctx := context.Background()
	fetch(t, d, srv, arts, r1, "v1")
	if _, err := d.WriteEnv(ctx, EnvWritePayload{Site: "shop", ReleaseID: r2, Compose: true, EnvFile: &EnvFile{Content: "A=1\n"}}, st()); err == nil {
		t.Fatal("a release that is not on the server was accepted")
	}
	res, err := d.WriteEnv(ctx, EnvWritePayload{Site: "shop", ReleaseID: r1, Compose: true, EnvFile: &EnvFile{Content: "DB_PASSWORD=pw-123456\n"}}, st())
	if err != nil || !res.(EnvWriteResult).Changed {
		t.Fatalf("%v %v", res, err)
	}
	if fi, err := os.Stat(d.o.FS.P("/run/falak/env/compose-shop.env")); err != nil || fi.Mode().Perm() != 0o400 {
		t.Fatalf("compose env file: %v %v", fi, err)
	}
}

type fakeContainers struct {
	missing  []string
	restored map[string]map[string]string
	release  string
}

func (f *fakeContainers) MissingSecrets(context.Context) ([]string, error) { return f.missing, nil }

func (f *fakeContainers) RestoreSiteSecrets(_ context.Context, site, release string, files map[string]string, _ io.Writer) ([]string, error) {
	if f.restored == nil {
		f.restored = map[string]map[string]string{}
	}
	f.restored[site], f.release = files, release
	return []string{"falak-" + site + "-blue"}, nil
}

func TestWriteEnvRestoresContainerSecrets(t *testing.T) {
	d, _, _, _, _ := newDeployer(t)
	fc := &fakeContainers{missing: []string{"api", "shop"}}
	d.o.Containers = fc
	if got := d.MissingSecrets(context.Background()); !slices.Equal(got, []string{"api", "shop"}) {
		t.Fatalf("missing = %v", got)
	}
	res, err := d.WriteEnv(context.Background(), EnvWritePayload{Site: "api", ReleaseID: r1, SecretFiles: []SecretFile{{Name: "DB_PASSWORD", Content: "pw-123456"}}}, st())
	if err != nil {
		t.Fatal(err)
	}
	if r := res.(EnvWriteResult); !r.Changed || !slices.Equal(r.Containers, []string{"falak-api-blue"}) || fc.restored["api"]["DB_PASSWORD"] != "pw-123456" || fc.release != r1 {
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
