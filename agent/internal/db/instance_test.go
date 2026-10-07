package db

import (
	"context"
	"encoding/json"
	"os"
	"slices"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

func TestCreateBody(t *testing.T) {
	db := New(Deps{FS: hostfs.FS{}})
	s := spec()
	s.CPUs = 1.5
	s.Publish = &Publish{Addresses: []string{"10.0.0.5", "100.64.3.2"}}
	b := db.createBody(s, "fp")

	if b.Image != "ghcr.io/othmanhaba/falak-postgres:17" || b.Labels[LabelInstance] != instID || b.Labels[LabelEngine] != "postgres" {
		t.Errorf("image/labels: %s %v", b.Image, b.Labels)
	}
	wantMounts := []docker.Mount{
		{Type: "bind", Source: "/var/lib/falak/volumes/" + volID + "/data", Target: "/var/lib/postgresql/data"},
		{Type: "bind", Source: "/var/lib/falak/volumes/" + volID + "/spool", Target: "/var/lib/falak/db/spool"},
		{Type: "bind", Source: "/run/falak/secrets/falak-db-" + instID, Target: "/run/secrets", ReadOnly: true},
		{Type: "bind", Source: "/etc/falak/db/" + instID + "/tls", Target: "/run/falak/db/tls", ReadOnly: true},
	}
	if !slices.Equal(b.HostConfig.Mounts, wantMounts) || len(b.HostConfig.Binds) != 0 {
		t.Errorf("mounts %+v", b.HostConfig.Mounts)
	}
	if b.HostConfig.Memory != 512<<20 || b.HostConfig.NanoCPUs != 1_500_000_000 || b.HostConfig.ShmSize != 128<<20 {
		t.Errorf("limits %+v", b.HostConfig)
	}
	if b.HostConfig.RestartPolicy.Name != "unless-stopped" || b.StopTimeout == nil || *b.StopTimeout != 60 {
		t.Errorf("restart/stop %+v %v", b.HostConfig.RestartPolicy, b.StopTimeout)
	}
	if b.Healthcheck == nil || strings.Join(b.Healthcheck.Test, " ") != "CMD falak-db health" {
		t.Errorf("healthcheck %+v", b.Healthcheck)
	}
	binds := b.HostConfig.PortBindings["5432/tcp"]
	if len(binds) != 3 || binds[0] != (docker.PortBinding{HostIP: "127.0.0.1", HostPort: "20001"}) || binds[1].HostIP != "10.0.0.5" || binds[2].HostIP != "100.64.3.2" {
		t.Errorf("port bindings %+v", binds)
	}
	if b.HostConfig.NetworkMode != s.Network || !slices.Equal(b.NetworkingConfig.EndpointsConfig[s.Network].Aliases, []string{"falak-db-" + instID}) {
		t.Errorf("network %s %+v", b.HostConfig.NetworkMode, b.NetworkingConfig)
	}
	env := strings.Join(b.Env, "\n")
	if !strings.Contains(env, "POSTGRES_PASSWORD_FILE=/run/secrets/password") || !strings.Contains(env, `"max_connections":200`) || strings.Contains(env, `"tls":false`) {
		t.Errorf("env %s", env)
	}

	// No certificate: TLS off; no host port: nothing published; aliases as given.
	s = spec()
	s.Engine, s.Version, s.HostPort, s.Network, s.Aliases = "redis", "8", 0, "falak-env-01hzyenv000000000000000001", []string{"falak-db-old", "cache"}
	b = db.createBody(s, "")
	if len(b.HostConfig.PortBindings) != 0 || b.HostConfig.ShmSize != 0 || len(b.HostConfig.Mounts) != 3 || b.HostConfig.Mounts[0].Target != "/data" {
		t.Errorf("redis body %+v", b.HostConfig)
	}
	if !strings.Contains(strings.Join(b.Env, " "), `"tls":false`) || !strings.Contains(strings.Join(b.Env, " "), "FALAK_DB_PASSWORD_FILE=/run/secrets/password") {
		t.Errorf("redis env %v", b.Env)
	}
	if got := b.NetworkingConfig.EndpointsConfig[s.Network].Aliases; !slices.Equal(got, []string{"falak-db-old", "cache"}) {
		t.Errorf("aliases %v", got)
	}

	// PostgreSQL 18's data directory; public access binds every address.
	s = spec()
	s.Version = "18"
	s.Publish = &Publish{Public: true}
	b = db.createBody(s, "fp")
	if b.HostConfig.Mounts[0].Target != "/var/lib/postgresql" || len(b.HostConfig.PortBindings["5432/tcp"]) != 1 || b.HostConfig.PortBindings["5432/tcp"][0].HostIP != "0.0.0.0" {
		t.Errorf("pg18 %+v", b.HostConfig)
	}
	if shmSize(16<<30) != 1<<30 || shmSize(128<<20) != 64<<20 {
		t.Error("shm bounds")
	}
}

func TestSpecValidation(t *testing.T) {
	for name, mut := range map[string]func(*InstanceSpec){
		"public address":   func(s *InstanceSpec) { s.Publish = &Publish{Addresses: []string{"203.0.113.9"}} },
		"any address":      func(s *InstanceSpec) { s.Publish = &Publish{Addresses: []string{"0.0.0.0"}} },
		"loopback publish": func(s *InstanceSpec) { s.Publish = &Publish{Addresses: []string{"127.0.0.1"}} },
		"publish no port":  func(s *InstanceSpec) { s.HostPort = 0; s.Publish = &Publish{Addresses: []string{"10.0.0.5"}} },
		"foreign network":  func(s *InstanceSpec) { s.Network = "bridge" },
		"engine":           func(s *InstanceSpec) { s.Engine = "sqlite" },
		"id":               func(s *InstanceSpec) { s.ID = "../x" },
		"memory":           func(s *InstanceSpec) { s.MemoryBytes = 1 << 20 },
		"digest":           func(s *InstanceSpec) { s.Digest = "sha256:nope" },
		"image with @":     func(s *InstanceSpec) { s.Image = "x@sha256:" + strings.Repeat("a", 64) },
		"settings":         func(s *InstanceSpec) { s.Settings = []byte(`[1]`) },
		"alias":            func(s *InstanceSpec) { s.Aliases = []string{"Bad_Alias"} },
	} {
		s := spec()
		mut(&s)
		if err := s.validate(); !commands.IsPayloadError(err) {
			t.Errorf("%s: %v", name, err)
		}
	}
	if err := spec().validate(); err != nil {
		t.Fatal(err)
	}
}

func createPayload() InstancePayload {
	s := spec()
	s.TLS = &TLS{Certificate: "CERT", PrivateKey: "KEY", CA: "CA"}
	return InstancePayload{Instance: s, Password: secret}
}

func TestInstanceCreateRunsTheContainerWithItsSecretsAsFiles(t *testing.T) {
	h := newHarness(t)
	res, err := h.db.InstanceCreate(context.Background(), createPayload(), stream())
	if err != nil {
		t.Fatal(err)
	}
	r := res.(InstanceResult)
	if !r.Changed || r.Health != "healthy" || r.ImageDigest != "sha256:"+strings.Repeat("d", 64) {
		t.Errorf("result %+v", r)
	}
	pw, err := os.ReadFile(h.path("/run/falak/secrets/falak-db-" + instID + "/password"))
	if err != nil || string(pw) != secret {
		t.Fatalf("password file %q %v", pw, err)
	}
	if st, _ := os.Stat(h.path("/run/falak/secrets/falak-db-" + instID)); st.Mode().Perm() != 0o555 {
		t.Errorf("secrets dir mode %v", st.Mode().Perm())
	}
	if st, _ := os.Stat(h.path("/run/falak/secrets")); st.Mode().Perm() != 0o700 {
		t.Errorf("secrets parent mode %v", st.Mode().Perm())
	}
	if key, _ := os.ReadFile(h.path("/etc/falak/db/" + instID + "/tls/server.key")); string(key) != "KEY" {
		t.Errorf("tls key %q", key)
	}
	for _, sub := range []string{"data", "spool"} {
		if _, err := os.Stat(h.path("/var/lib/falak/volumes/" + volID + "/" + sub)); err != nil {
			t.Error(err)
		}
	}
	body := h.dock.bodies["falak-db-"+instID]
	b, _ := json.Marshal(body)
	if strings.Contains(string(b), secret) || strings.Contains(string(b), "KEY\"") {
		t.Errorf("a secret is in the container spec: %s", b)
	}
	if h.dock.netLabels[spec().Network]["falak.network"] != "environment" {
		t.Errorf("network not created: %v", h.dock.netLabels)
	}

	// Same spec again (an update without tls keeps the installed certificate): nothing recreated.
	p := createPayload()
	p.Instance.TLS = nil
	h.dock.calls = nil
	res, err = h.db.InstanceUpdate(context.Background(), p, stream())
	if err != nil || res.(InstanceResult).Changed || h.dock.called("create") || h.dock.called("stop") {
		t.Fatalf("idempotent update: %+v %v %v", res, err, h.dock.calls)
	}
	if _, err := os.Stat(h.path("/etc/falak/db/" + instID + "/tls/server.crt")); err != nil {
		t.Error("an update without tls removed the certificate")
	}

	// A settings change recreates the container on the same volume (stopped with its 60 s grace).
	p.Instance.Settings = []byte(`{"max_connections": 300}`)
	res, err = h.db.InstanceUpdate(context.Background(), p, stream())
	if err != nil || !res.(InstanceResult).Changed || !h.dock.called("stop falak-db-"+instID+" 1m0s") || !h.dock.called("create") {
		t.Fatalf("settings update: %+v %v %v", res, err, h.dock.calls)
	}

	// A tag that now points at a newer image (a minor upgrade) recreates it too.
	h.dock.calls = nil
	h.dock.imageIDs[spec().Image] = "sha256:img2"
	if _, err := h.db.InstanceUpdate(context.Background(), p, stream()); err != nil || !h.dock.called("create") {
		t.Fatalf("new image: %v %v", err, h.dock.calls)
	}
}

func TestInstanceCreatePinsTheDigest(t *testing.T) {
	h := newHarness(t)
	p := createPayload()
	p.Instance.Digest = "sha256:" + strings.Repeat("a", 64)
	res, err := h.db.InstanceCreate(context.Background(), p, stream())
	if err != nil {
		t.Fatal(err)
	}
	want := "ghcr.io/othmanhaba/falak-postgres@sha256:" + strings.Repeat("a", 64)
	if h.dock.pulls[0] != want || h.dock.bodies["falak-db-"+instID].Image != want || res.(InstanceResult).ImageDigest != p.Instance.Digest {
		t.Errorf("pulled %v body %s", h.dock.pulls, h.dock.bodies["falak-db-"+instID].Image)
	}
	// A registry answering with another digest is refused.
	h2 := newHarness(t)
	h2.dock.images[want] = []string{"ghcr.io/othmanhaba/falak-postgres@sha256:" + strings.Repeat("b", 64)}
	if _, err := h2.db.InstanceCreate(context.Background(), p, stream()); err == nil || !strings.Contains(err.Error(), "pinned digest") {
		t.Errorf("digest mismatch: %v", err)
	}
}

func TestInstanceCreateWaitsForTheVolumeAndFailsUnhealthy(t *testing.T) {
	h := newHarness(t)
	h.db.d.Mounted = func(string) bool { return false }
	if _, err := h.db.InstanceCreate(context.Background(), createPayload(), stream()); err == nil || !strings.Contains(err.Error(), "not mounted") {
		t.Fatalf("unmounted volume: %v", err)
	}
	h = newHarness(t)
	h.dock.health = "unhealthy"
	if _, err := h.db.InstanceCreate(context.Background(), createPayload(), stream()); err == nil || !strings.Contains(err.Error(), "not healthy") {
		t.Fatalf("unhealthy: %v", err)
	}
}

func TestInstancePasswordRotatesThroughAFile(t *testing.T) {
	h := newHarness(t)
	if _, err := h.db.InstanceCreate(context.Background(), createPayload(), stream()); err != nil {
		t.Fatal(err)
	}
	var seen string
	h.run.OnFunc("docker exec", func(c runnertest.Call) (runner.Result, error) {
		b, _ := os.ReadFile(h.path("/run/falak/secrets/falak-db-" + instID + "/.password.new"))
		seen = string(b)
		return runner.Result{Stdout: []byte(`{"changed":true}`)}, nil
	})
	if _, err := h.db.InstancePassword(context.Background(), PasswordPayload{ID: instID, Engine: "postgres", Password: "n3w"}, stream()); err != nil {
		t.Fatal(err)
	}
	line := h.run.Lines()[0]
	if line != "docker exec falak-db-"+instID+" falak-db password set --file /run/secrets/.password.new" || seen != "n3w" {
		t.Errorf("exec %q, file %q", line, seen)
	}
	pw, _ := os.ReadFile(h.path("/run/falak/secrets/falak-db-" + instID + "/password"))
	if string(pw) != "n3w" {
		t.Errorf("password file %q", pw)
	}
	if _, err := os.Stat(h.path("/run/falak/secrets/falak-db-" + instID + "/.password.new")); err == nil {
		t.Error(".password.new left behind")
	}

	// falak-db failing keeps the current password file.
	h.run.OnFunc("docker exec", func(runnertest.Call) (runner.Result, error) { return runner.Result{ExitCode: 1}, nil })
	h2 := newHarness(t)
	h2.db.InstanceCreate(context.Background(), createPayload(), stream())
	h2.run.OnFunc("docker exec", func(runnertest.Call) (runner.Result, error) {
		return runner.Result{ExitCode: 1, Stderr: []byte("falak-db: no")}, nil
	})
	if _, err := h2.db.InstancePassword(context.Background(), PasswordPayload{ID: instID, Engine: "postgres", Password: "other"}, stream()); err == nil {
		t.Fatal("a failed rotation succeeded")
	}
	if pw, _ := os.ReadFile(h2.path("/run/falak/secrets/falak-db-" + instID + "/password")); string(pw) != secret {
		t.Errorf("password file after a failure %q", pw)
	}
}

func TestInstanceSecretsAfterAReboot(t *testing.T) {
	h := newHarness(t)
	if _, err := h.db.InstanceCreate(context.Background(), createPayload(), stream()); err != nil {
		t.Fatal(err)
	}
	dir := h.path("/run/falak/secrets/falak-db-" + instID)
	os.Chmod(dir, 0o700)
	os.RemoveAll(dir)
	h.dock.containers["falak-db-"+instID].State.Running = false
	if r := h.db.Report(context.Background()); len(r) != 1 || !r[0].SecretsMissing || r[0].ID != instID {
		t.Fatalf("report %+v", r)
	}
	res, err := h.db.InstanceSecrets(context.Background(), PasswordPayload{ID: instID, Engine: "postgres", Password: secret}, stream())
	if err != nil || !res.(SecretsResult).Restored || !res.(SecretsResult).Started {
		t.Fatalf("%+v %v", res, err)
	}
	if pw, _ := os.ReadFile(dir + "/password"); string(pw) != secret {
		t.Errorf("password %q", pw)
	}
	// Present: left alone.
	res, _ = h.db.InstanceSecrets(context.Background(), PasswordPayload{ID: instID, Engine: "postgres", Password: "other"}, stream())
	if res.(SecretsResult).Restored {
		t.Error("a present secrets directory was rewritten")
	}
	if r := h.db.Report(context.Background()); r[0].SecretsMissing || r[0].State != "running" || r[0].Health != "healthy" {
		t.Errorf("report %+v", r)
	}
}

func TestInstanceRestartStopDelete(t *testing.T) {
	h := newHarness(t)
	if _, err := h.db.InstanceCreate(context.Background(), createPayload(), stream()); err != nil {
		t.Fatal(err)
	}
	if res, err := h.db.InstanceRestart(context.Background(), IDPayload{ID: instID}, stream()); err != nil || res.(ChangedResult).Health != "healthy" {
		t.Fatalf("restart %+v %v", res, err)
	}
	if res, err := h.db.InstanceStop(context.Background(), IDPayload{ID: instID}, stream()); err != nil || !res.(ChangedResult).Changed {
		t.Fatalf("stop %+v %v", res, err)
	}
	res, err := h.db.InstanceDelete(context.Background(), IDPayload{ID: instID}, stream())
	if err != nil || !res.(ChangedResult).Changed || h.dock.containers["falak-db-"+instID] != nil {
		t.Fatalf("delete %+v %v", res, err)
	}
	for _, p := range []string{"/run/falak/secrets/falak-db-" + instID, "/etc/falak/db/" + instID} {
		if _, err := os.Stat(h.path(p)); err == nil {
			t.Errorf("%s left behind", p)
		}
	}
	if _, err := os.Stat(h.path("/var/lib/falak/volumes/" + volID + "/data")); err != nil {
		t.Error("the data volume was touched")
	}
	// Never created: nothing to do.
	if res, err := h.db.InstanceDelete(context.Background(), IDPayload{ID: instID2}, stream()); err != nil || res.(ChangedResult).Changed {
		t.Errorf("delete of nothing %+v %v", res, err)
	}
	if res, err := h.db.InstanceStop(context.Background(), IDPayload{ID: instID2}, stream()); err != nil || res.(ChangedResult).Changed {
		t.Errorf("stop of nothing %+v %v", res, err)
	}
}

func TestInstanceUpgradeCopiesThenMovesTheAlias(t *testing.T) {
	h := newHarness(t)
	ctx := context.Background()
	if _, err := h.db.InstanceCreate(ctx, createPayload(), stream()); err != nil {
		t.Fatal(err)
	}
	target := createPayload()
	target.Instance.ID, target.Instance.Version, target.Instance.Image = instID2, "18", "ghcr.io/othmanhaba/falak-postgres:18"
	if _, err := h.db.InstanceCreate(ctx, target, stream()); err != nil {
		t.Fatal(err)
	}
	var restored, specSeen string
	h.run.OnFunc("docker exec falak-db-"+instID+" falak-db backup", func(runnertest.Call) (runner.Result, error) {
		return runner.Result{Stdout: []byte("PGDMP-data"), Stderr: []byte(`falak-db-result: {"bytes":10}` + "\n")}, nil
	})
	h.run.OnFunc("docker exec -i falak-db-"+instID2+" falak-db restore", func(c runnertest.Call) (runner.Result, error) {
		restored = c.Stdin
		return runner.Result{Stdout: []byte(`{}`)}, nil
	})
	h.run.OnFunc("docker exec falak-db-"+instID2+" falak-db user apply", func(c runnertest.Call) (runner.Result, error) {
		path := c.Args[len(c.Args)-1]
		b, _ := os.ReadFile(h.path("/run/falak/secrets/falak-db-" + instID2 + strings.TrimPrefix(path, "/run/secrets")))
		specSeen = string(b)
		return runner.Result{Stdout: []byte(`{"changed":true}`)}, nil
	})
	p := UpgradePayload{Mode: "major", Source: UpgradeEnd{instID, "postgres"}, Target: UpgradeEnd{instID2, "postgres"},
		Databases: []UpgradeDatabase{{Name: "app"}}, Users: []UserSpec{{Username: "app", Password: "u-pw", Grants: []Grant{{Database: "app"}}}},
		Network: spec().Network, Alias: "falak-db-" + instID}
	res, err := h.db.InstanceUpgrade(ctx, p, stream())
	if err != nil {
		t.Fatal(err)
	}
	r := res.(UpgradeResult)
	if len(r.Databases) != 1 || r.Databases[0].Bytes != 10 || restored != "PGDMP-data" {
		t.Errorf("result %+v restored %q", r, restored)
	}
	if !strings.Contains(specSeen, `"password":"u-pw"`) {
		t.Errorf("user spec %q", specSeen)
	}
	lines := strings.Join(h.run.Lines(), "\n")
	if strings.Contains(lines, "u-pw") {
		t.Error("a password is in argv")
	}
	for _, want := range []string{"falak-db database create --name app", "falak-db user apply --spec /run/secrets/.spec-"} {
		if !strings.Contains(lines, want) {
			t.Errorf("missing %q in %s", want, lines)
		}
	}
	if got := h.dock.networks[spec().Network]["falak-db-"+instID2]; !slices.Equal(got, []string{"falak-db-" + instID, "falak-db-" + instID2}) {
		t.Errorf("target aliases %v", got)
	}
	if !h.dock.called("disconnect "+spec().Network+" falak-db-"+instID) || !h.dock.called("stop falak-db-"+instID+" 1m0s") {
		t.Errorf("source not retired: %v", h.dock.calls)
	}
	p.Source.Engine = "redis"
	if _, err := h.db.InstanceUpgrade(ctx, p, stream()); !commands.IsPayloadError(err) {
		t.Errorf("key-value upgrade accepted: %v", err)
	}
}
