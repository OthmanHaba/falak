package docker

import (
	"encoding/base64"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/kiln/agent/internal/runner"
)

// addComposeContainer registers a running container of a compose project in the fake engine.
func addComposeContainer(e *fakeEngine, project, service, image, imageID string) *fcont {
	e.mu.Lock()
	defer e.mu.Unlock()
	e.seq++
	c := &fcont{id: "cc" + service, name: project + "-" + service + "-1", image: image, imageID: imageID, running: true, created: int64(e.seq),
		body: CreateBody{Image: image, Labels: map[string]string{LabelComposeProject: project, LabelComposeService: service, "kiln.site": project}}}
	e.containers[c.id] = c
	e.images[image] = imageID
	return c
}

func TestComposeUpWaitEnvFileAndStatus(t *testing.T) {
	s, e, fr, _, root := newSvc(t)
	app := addComposeContainer(e, "shop", "app", "registry.kiln.test/kiln/shop-app@sha256:"+strings.Repeat("a", 64), "sha256:appimg")
	app.health = "healthy"
	app.ports = map[string][]PortBinding{"8080/tcp": {{HostIP: "127.0.0.1", HostPort: "3001"}}}
	redis := addComposeContainer(e, "shop", "redis", "redis:7.4.1", "sha256:redisimg")
	redis.restarts = 2
	e.repoDigests["sha256:redisimg"] = []string{"redis@sha256:" + strings.Repeat("b", 64)}
	addComposeContainer(e, "other", "web", "nginx:1", "sha256:nginx")

	dir := "/srv/kiln/sites/shop/releases/01J00000000000000000000000"
	fin, _ := exec1(t, s, "docker.compose.up", ComposeUpPayload{Project: "shop", Directory: dir,
		Files:          []ComposeFile{{Name: "compose.yaml", Content: "services: {}\n"}, {Name: ".env", Content: "SECRET=x\n"}},
		ProjectEnvFile: ".env", Wait: true, WaitTimeoutS: 90, RegistryAuth: &Auth{Username: "u", Password: "p", Server: "registry.kiln.test"}})
	if fin.Error != "" {
		t.Fatalf("%+v", fin)
	}
	c := fr.Calls()[0]
	if c.Line != "docker compose -p shop --env-file .env -f compose.yaml up -d --pull missing --remove-orphans --wait --wait-timeout 90" {
		t.Fatalf("line %q", c.Line)
	}
	if len(c.Env) != 1 || !strings.HasPrefix(c.Env[0], "DOCKER_CONFIG=") {
		t.Fatalf("env %v", c.Env)
	}
	if _, err := os.Stat(strings.TrimPrefix(c.Env[0], "DOCKER_CONFIG=")); !os.IsNotExist(err) {
		t.Fatalf("docker config dir not removed: %v", err)
	}
	st, _ := os.Stat(filepath.Join(root, dir, ".env"))
	if st == nil || st.Mode().Perm() != 0o600 {
		t.Fatalf(".env mode %v", st)
	}
	res := fin.Result.(ComposeUpResult)
	if len(res.Services) != 2 || res.Services[0].Service != "app" || res.Services[1].Service != "redis" {
		t.Fatalf("services %+v", res.Services)
	}
	a, r := res.Services[0], res.Services[1]
	if a.ImageDigest != "sha256:"+strings.Repeat("a", 64) || a.Health != "healthy" || a.State != "running" ||
		len(a.Ports) != 1 || a.Ports[0].HostPort != 3001 || a.Ports[0].ContainerPort != 8080 {
		t.Fatalf("app %+v", a)
	}
	if r.ImageDigest != "sha256:"+strings.Repeat("b", 64) || r.Restarts != 2 {
		t.Fatalf("redis %+v", r)
	}
}

func TestComposeUpFailureReportsServices(t *testing.T) {
	s, e, fr, _, _ := newSvc(t)
	c := addComposeContainer(e, "shop", "app", "app:1", "sha256:x")
	c.health = "unhealthy"
	fr.On("docker compose -p shop", runner.Result{ExitCode: 1, Stderr: []byte("dependency failed to start: container shop-app-1 is unhealthy")})
	fin, col := exec1(t, s, "docker.compose.up", ComposeUpPayload{Project: "shop", Directory: "/srv/x", Wait: true})
	if fin.ExitCode == nil || *fin.ExitCode != 1 || len(fin.Result.(ComposeUpResult).Services) != 1 {
		t.Fatalf("%+v", fin)
	}
	if !strings.Contains(col.Output("stderr"), "service app: running (unhealthy)") {
		t.Fatalf("output %q", col.Output("stderr"))
	}
	fin, _ = exec1(t, s, "docker.compose.up", ComposeUpPayload{Project: "shop", Directory: "/srv/x", ProjectEnvFile: "../.env"})
	if fin.ExitCode == nil || *fin.ExitCode != 2 {
		t.Fatalf("bad env file accepted: %+v", fin)
	}
}

func TestComposePull(t *testing.T) {
	s, _, fr, _, root := newSvc(t)
	fin, _ := exec1(t, s, "docker.compose.pull", ComposePullPayload{Project: "shop", Directory: "/srv/r",
		Files: []ComposeFile{{Name: "compose.yaml", Content: "services: {}\n"}, {Name: ".env", Content: "A=1\n"}}, ProjectEnvFile: ".env"})
	if fin.Error != "" || fr.Calls()[0].Line != "docker compose -p shop --env-file .env -f compose.yaml pull --quiet" {
		t.Fatalf("%+v %v", fin, fr.Lines())
	}
	if b, _ := os.ReadFile(filepath.Join(root, "srv/r/.env")); string(b) != "A=1\n" {
		t.Fatalf(".env %q", b)
	}
	fin, _ = exec1(t, s, "docker.compose.pull", ComposePullPayload{Project: "shop", Directory: "/srv/r", Services: []string{"a;b"}})
	if fin.ExitCode == nil || *fin.ExitCode != 2 {
		t.Fatalf("bad service accepted %+v", fin)
	}
}

func TestComposeAssetsAreWrittenUnderRepo(t *testing.T) {
	s, _, fr, _, root := newSvc(t)
	b64 := func(s string) string { return base64.StdEncoding.EncodeToString([]byte(s)) }
	fin, _ := exec1(t, s, "docker.compose.pull", ComposePullPayload{Project: "shop", Directory: "/srv/r",
		Files:  []ComposeFile{{Name: "compose.yaml", Content: "services: {}\n"}},
		Assets: []ComposeAsset{{Path: "deploy/nginx.conf", Content: b64("server {}\n")}, {Path: "bin/entry.sh", Content: b64("#!/bin/sh\n"), Mode: 0o755}}})
	if fin.Error != "" || fr.Calls()[0].Line != "docker compose -p shop -f compose.yaml pull --quiet" {
		t.Fatalf("%+v %v", fin, fr.Lines())
	}
	if b, _ := os.ReadFile(filepath.Join(root, "srv/r/repo/deploy/nginx.conf")); string(b) != "server {}\n" {
		t.Fatalf("asset %q", b)
	}
	if st, _ := os.Stat(filepath.Join(root, "srv/r/repo/bin/entry.sh")); st == nil || st.Mode().Perm() != 0o755 {
		t.Fatalf("executable asset mode %v", st)
	}
	for _, bad := range []ComposeAsset{
		{Path: "../escape", Content: b64("x")},
		{Path: "a/../../b", Content: b64("x")},
		{Path: "/etc/passwd", Content: b64("x")},
		{Path: "ok.txt", Content: "not base64!"},
		{Path: "ok.txt", Content: b64("x"), Mode: 0o4755},
	} {
		fin, _ := exec1(t, s, "docker.compose.pull", ComposePullPayload{Project: "shop", Directory: "/srv/r", Assets: []ComposeAsset{bad}})
		if fin.ExitCode == nil || *fin.ExitCode != 2 {
			t.Fatalf("bad asset %+v accepted: %+v", bad, fin)
		}
	}
}

func TestComposePsWithStats(t *testing.T) {
	s, e, _, _, _ := newSvc(t)
	addComposeContainer(e, "shop", "app", "app:1", "sha256:x")
	stopped := addComposeContainer(e, "shop", "worker", "app:1", "sha256:x")
	stopped.running = false
	fin, _ := exec1(t, s, "docker.compose.ps", ComposePsPayload{Project: "shop", Stats: true})
	res := fin.Result.(ComposePsResult)
	if fin.Error != "" || len(res.Services) != 2 {
		t.Fatalf("%+v", fin)
	}
	app, worker := res.Services[0], res.Services[1]
	// (3000-1000)/(20000-10000) * 2 cpus * 100 = 40%; memory = 5000 - 1000 inactive_file
	if app.CPUPercent == nil || *app.CPUPercent != 40 || *app.MemoryBytes != 4000 || *app.MemoryLimit != 100000 {
		t.Fatalf("app stats %+v", app)
	}
	if worker.State != "exited" || worker.ExitCode == nil || worker.CPUPercent != nil {
		t.Fatalf("worker %+v", worker)
	}
	fin, _ = exec1(t, s, "docker.compose.ps", ComposePsPayload{Project: "nothing"})
	if fin.Error != "" || fin.Result.(ComposePsResult).Services == nil {
		t.Fatalf("empty project %+v", fin)
	}
}

func TestComposeRestart(t *testing.T) {
	s, e, _, _, _ := newSvc(t)
	addComposeContainer(e, "shop", "app", "app:1", "sha256:x")
	addComposeContainer(e, "shop", "redis", "redis:7", "sha256:r")
	fin, _ := exec1(t, s, "docker.compose.restart", ComposeRestartPayload{Project: "shop", Services: []string{"redis"}})
	if fin.Error != "" || strings.Join(fin.Result.(ComposeRestartResult).Restarted, ",") != "redis" || strings.Join(e.restarts, ",") != "shop-redis-1" {
		t.Fatalf("%+v %v", fin, e.restarts)
	}
	fin, _ = exec1(t, s, "docker.compose.restart", ComposeRestartPayload{Project: "shop"})
	if strings.Join(fin.Result.(ComposeRestartResult).Restarted, ",") != "app,redis" {
		t.Fatalf("%+v", fin)
	}
	fin, _ = exec1(t, s, "docker.compose.restart", ComposeRestartPayload{Project: "shop", Services: []string{"missing"}})
	if fin.Error == "" {
		t.Fatalf("unknown service restarted: %+v", fin)
	}
}
