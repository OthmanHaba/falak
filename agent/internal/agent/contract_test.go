package agent

import (
	"bytes"
	"encoding/json"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"testing"
	"time"

	"github.com/santhosh-tekuri/jsonschema/v6"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/db"
	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/fngateway"
	"github.com/OthmanHaba/falak/agent/internal/functions"
	"github.com/OthmanHaba/falak/agent/internal/transport"
)

// Catalogue is the v1 command catalogue from ARCHITECTURE.md §3.
var Catalogue = []string{
	"system.facts", "system.exec", "system.write_file", "system.package.install", "system.user.create", "system.ssh_key.sync", "system.upgrade_agent",
	"provision.apply", "provision.inspect",
	"runtime.php.install", "runtime.php.configure", "runtime.node.install", "runtime.bun.install", "runtime.deno.install", "runtime.frankenphp.configure", "runtime.fpm.pool",
	"edge.caddy.apply", "edge.cert.install",
	"deploy.fetch", "deploy.prepare", "deploy.hook", "deploy.activate", "deploy.rollback", "deploy.prune", "deploy.container.swap",
	"proc.apply", "proc.restart", "proc.status",
	"cron.apply",
	"db.create", "db.drop", "db.user.apply", "db.backup", "db.restore", "db.redis.apply", "db.redis.remove",
	"net.firewall.apply", "net.wireguard.apply", "net.tunnel.apply",
	"fn.release.apply", "fn.release.remove", "fn.run", "fn.status",
	"docker.pull", "docker.run", "docker.stop", "docker.compose.up", "docker.compose.down", "docker.compose.pull", "docker.compose.ps", "docker.compose.restart", "docker.prune",
	"telemetry.configure",
	"terminal.open", "terminal.input", "terminal.resize", "terminal.close",
}

const (
	contractsDir = "../../../contracts/agent-protocol"
	examplesDir  = "../../testdata/command-examples"
	idBase       = "https://falak.sh/agent-protocol/"
)

func compiler(t *testing.T) *jsonschema.Compiler {
	t.Helper()
	c := jsonschema.NewCompiler()
	c.DefaultDraft(jsonschema.Draft2020)
	c.AssertFormat()
	add := func(path, id string) {
		f, err := os.Open(path)
		if err != nil {
			t.Fatal(err)
		}
		defer f.Close()
		doc, err := jsonschema.UnmarshalJSON(f)
		if err != nil {
			t.Fatalf("%s: %v", path, err)
		}
		if err := c.AddResource(id, doc); err != nil {
			t.Fatalf("%s: %v", path, err)
		}
	}
	top, _ := filepath.Glob(filepath.Join(contractsDir, "*.schema.json"))
	for _, p := range top {
		add(p, idBase+filepath.Base(p))
	}
	cmds, _ := filepath.Glob(filepath.Join(contractsDir, "commands", "*.schema.json"))
	for _, p := range cmds {
		add(p, idBase+"commands/"+filepath.Base(p))
	}
	return c
}

func load(t *testing.T, path string) any {
	t.Helper()
	b, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	v, err := jsonschema.UnmarshalJSON(bytes.NewReader(b))
	if err != nil {
		t.Fatalf("%s: %v", path, err)
	}
	return v
}

func TestCatalogueSchemasRegistryAndExamplesAgree(t *testing.T) {
	files, _ := filepath.Glob(filepath.Join(contractsDir, "commands", "*.schema.json"))
	var schemaTypes []string
	for _, f := range files {
		schemaTypes = append(schemaTypes, strings.TrimSuffix(filepath.Base(f), ".schema.json"))
	}
	want := append([]string(nil), Catalogue...)
	sort.Strings(want)
	sort.Strings(schemaTypes)
	if strings.Join(schemaTypes, ",") != strings.Join(want, ",") {
		t.Fatalf("schema files != catalogue\n files: %v\n want:  %v", schemaTypes, want)
	}
	reg, cleanup := buildTestRegistry(t)
	defer cleanup()
	if got := reg.Types(); strings.Join(got, ",") != strings.Join(want, ",") {
		t.Fatalf("registered executors != catalogue\n got:  %v\n want: %v", got, want)
	}
}

func TestEveryExampleValidatesAndDecodes(t *testing.T) {
	c := compiler(t)
	reg, cleanup := buildTestRegistry(t)
	defer cleanup()
	for _, typ := range Catalogue {
		t.Run(typ, func(t *testing.T) {
			id := idBase + "commands/" + typ + ".schema.json"
			sch, err := c.Compile(id)
			if err != nil {
				t.Fatalf("schema does not compile: %v", err)
			}
			if raw, _ := os.ReadFile(filepath.Join(contractsDir, "commands", typ+".schema.json")); bytes.Contains(raw, []byte(`"result"`)) {
				if _, err := c.Compile(id + "#/$defs/result"); err != nil {
					t.Fatalf("$defs.result does not compile: %v", err)
				}
			}
			path := filepath.Join(examplesDir, typ+".json")
			if err := sch.Validate(load(t, path)); err != nil {
				t.Fatalf("example %s invalid: %v", path, err)
			}
			raw, _ := os.ReadFile(path)
			ex, ok := reg.Get(typ)
			if !ok {
				t.Fatalf("no executor")
			}
			pc, ok := ex.(commands.PayloadChecker)
			if !ok {
				t.Fatalf("executor for %s is not built with commands.Typed (cannot check payload decoding)", typ)
			}
			if err := pc.CheckPayload(raw); err != nil {
				t.Fatalf("example does not decode into the executor payload type: %v", err)
			}
			// The envelope carrying it must be valid too.
			env, _ := json.Marshal(commands.Envelope{ID: "01J9Z8Y7X6W5V4T3S2R1Q0P9NA", Type: typ, TimeoutS: 600, IdempotencyKey: "k", Payload: raw})
			es, err := c.Compile(idBase + "envelope.schema.json")
			if err != nil {
				t.Fatal(err)
			}
			v, _ := jsonschema.UnmarshalJSON(bytes.NewReader(env))
			if err := es.Validate(v); err != nil {
				t.Fatalf("envelope invalid: %v", err)
			}
		})
	}
}

func TestSchemasRejectInvalidPayloads(t *testing.T) {
	c := compiler(t)
	bad := map[string]string{
		"deploy.fetch":           `{"site":"shop","release_id":"x","artifact":{"url":"http://x","sha256":"nope"}}`,
		"proc.apply":             `{"programs":[{"name":"w","command":[],"restart":"sometimes"}]}`,
		"edge.caddy.apply":       `{"sites":[{"id":"a","domains":[],"kind":"php_fpm"}]}`,
		"net.firewall.apply":     `{"rules":[{"id":"x","ports":["http"]}]}`,
		"system.exec":            `{"script":"true","unexpected":1}`,
		"docker.compose.up":      `{"project":"shop","directory":"/srv/x","project_env_file":"../.env"}`,
		"docker.compose.ps":      `{"project":"Shop!"}`,
		"docker.compose.restart": `{"project":"shop","services":["a b"]}`,
		"docker.compose.pull":    `{"project":"shop"}`,
		"fn.release.apply":       `{"site":"hello","release":"r1","image":"i","entrypoint":"../index.ts","files":[{"path":"../index.ts","content":""}]}`,
		"fn.status":              `{"site":"Hello World"}`,
		"fn.run":                 `{"site":"hello","schedule":"../x"}`,
	}
	for typ, payload := range bad {
		sch, err := c.Compile(idBase + "commands/" + typ + ".schema.json")
		if err != nil {
			t.Fatal(err)
		}
		v, _ := jsonschema.UnmarshalJSON(strings.NewReader(payload))
		if sch.Validate(v) == nil {
			t.Errorf("%s: invalid payload accepted", typ)
		}
	}
}

// Results of the compose executors validate against their schemas' $defs.result.
func TestComposeResultsValidate(t *testing.T) {
	c := compiler(t)
	cpu, mem := 12.5, int64(64<<20)
	code := 137
	svc := docker.ServiceStatus{Service: "app", ContainerID: "abc", ContainerName: "shop-app-1", State: "exited", ExitCode: &code, Health: "unhealthy",
		Image: "registry.falak.local/falak/shop/app@sha256:" + strings.Repeat("a", 64), ImageDigest: "sha256:" + strings.Repeat("a", 64),
		Ports: []docker.PortStatus{{HostIP: "127.0.0.1", HostPort: 3001, ContainerPort: 8080, Protocol: "tcp"}}, Restarts: 3,
		StartedAt: "2026-09-27T10:00:00Z", CPUPercent: &cpu, MemoryBytes: &mem, MemoryLimit: &mem}
	for typ, res := range map[string]any{
		"docker.compose.up":      docker.ComposeUpResult{ExitCode: 1, Services: []docker.ServiceStatus{svc}},
		"docker.compose.ps":      docker.ComposePsResult{Services: []docker.ServiceStatus{svc}},
		"docker.compose.restart": docker.ComposeRestartResult{Restarted: []string{"app"}},
		"docker.compose.pull":    docker.ExitResult{ExitCode: 0},
	} {
		sch, err := c.Compile(idBase + "commands/" + typ + ".schema.json#/$defs/result")
		if err != nil {
			t.Fatal(err)
		}
		b, _ := json.Marshal(res)
		v, _ := jsonschema.UnmarshalJSON(bytes.NewReader(b))
		if err := sch.Validate(v); err != nil {
			t.Errorf("%s result invalid: %v\n%s", typ, err, b)
		}
	}
}

func TestProtocolDocumentsValidate(t *testing.T) {
	c := compiler(t)
	evs, _ := c.Compile(idBase + "event.schema.json")
	p := 0.5
	code := 0
	for _, ev := range []commands.Event{
		{CommandID: "c", Seq: 0, Kind: commands.KindStarted, At: time.Now()},
		{CommandID: "c", Seq: 1, Kind: commands.KindOutput, At: time.Now(), Stream: "stdout", Data: "hi"},
		{CommandID: "c", Seq: 2, Kind: commands.KindProgress, At: time.Now(), Progress: &p},
		{CommandID: "c", Seq: 3, Kind: commands.KindFinished, At: time.Now(), ExitCode: &code, Result: map[string]any{"changed": true}},
	} {
		b, _ := json.Marshal(ev)
		v, _ := jsonschema.UnmarshalJSON(bytes.NewReader(b))
		if err := evs.Validate(v); err != nil {
			t.Fatalf("event %s invalid: %v", ev.Kind, err)
		}
	}
	hbs, _ := c.Compile(idBase + "heartbeat.schema.json")
	b, _ := json.Marshal(transport.Heartbeat{At: time.Now(), UptimeS: 5, Load: [3]float64{0.1, 0.2, 0.3}, RunningCommands: []string{}})
	v, _ := jsonschema.UnmarshalJSON(bytes.NewReader(b))
	if err := hbs.Validate(v); err != nil {
		t.Fatalf("heartbeat invalid: %v", err)
	}
}

// Results of the fn.* executors validate against their schemas' $defs.result.
func TestFunctionResultsValidate(t *testing.T) {
	c := compiler(t)
	at := time.Date(2026, 9, 30, 12, 0, 0, 0, time.UTC)
	for typ, res := range map[string]any{
		"fn.release.apply":  functions.ApplyResult{Release: "r2", PreviousRelease: "r1", Installed: true, BootMS: 240},
		"fn.release.remove": map[string]bool{"removed": true},
		"fn.run":            functions.RunResult{ExitCode: 0, DurationMS: 812},
		"fn.status": functions.StatusResult{Functions: []fngateway.Status{
			{Site: "hello", Release: "r2", Running: 1, InFlight: 3, ColdStarts: 2, Requests: 40, LastRequestAt: &at},
			{Site: "idle", Release: "r1"},
		}},
	} {
		sch, err := c.Compile(idBase + "commands/" + typ + ".schema.json#/$defs/result")
		if err != nil {
			t.Fatal(err)
		}
		b, _ := json.Marshal(res)
		v, _ := jsonschema.UnmarshalJSON(bytes.NewReader(b))
		if err := sch.Validate(v); err != nil {
			t.Errorf("%s result invalid: %v\n%s", typ, err, b)
		}
	}
}

// db.backup / db.restore name a SQL database or a Redis / Valkey instance (feature db.redis.backup): each engine's
// names only, and the key-value results validate.
func TestDatabaseBackupSchemasPerEngine(t *testing.T) {
	c := compiler(t)
	dest := `"destination":{"kind":"presigned_url","url":"https://s3.example.com/b/k?X-Amz-Signature=x"}`
	src := `"source":{"kind":"url","url":"https://s3.example.com/b/k"}`
	for payload, valid := range map[string]bool{
		`{"engine":"redis","database":"cache-1",` + dest + `}`:     true,
		`{"engine":"valkey","database":"sessions_2",` + dest + `}`: true,
		`{"engine":"redis","database":"Cache",` + dest + `}`:       false,
		`{"engine":"valkey","database":"9lives",` + dest + `}`:     false,
		`{"engine":"mysql","database":"shop_db",` + dest + `}`:     true,
		`{"engine":"mysql","database":"shop-db",` + dest + `}`:     false,
		`{"engine":"memcached","database":"cache",` + dest + `}`:   false,
	} {
		for typ, body := range map[string]string{"db.backup": payload, "db.restore": strings.Replace(payload, dest, src, 1)} {
			sch, err := c.Compile(idBase + "commands/" + typ + ".schema.json")
			if err != nil {
				t.Fatal(err)
			}
			v, _ := jsonschema.UnmarshalJSON(strings.NewReader(body))
			if err := sch.Validate(v); (err == nil) != valid {
				t.Errorf("%s %s: valid=%v, err=%v", typ, body, valid, err)
			}
		}
	}
	// The recorded size a restore gets back (feature db.redis.restore_checks).
	sch, err := c.Compile(idBase + "commands/db.restore.schema.json")
	if err != nil {
		t.Fatal(err)
	}
	for body, valid := range map[string]bool{
		`{"engine":"redis","database":"cache",` + src + `,"uncompressed_bytes":1048576}`: true,
		`{"engine":"redis","database":"cache",` + src + `,"uncompressed_bytes":0}`:       false,
	} {
		v, _ := jsonschema.UnmarshalJSON(strings.NewReader(body))
		if err := sch.Validate(v); (err == nil) != valid {
			t.Errorf("%s: valid=%v, err=%v", body, valid, err)
		}
	}
	for typ, res := range map[string]any{
		"db.backup":  db.BackupResult{SizeBytes: 10, SHA256: strings.Repeat("a", 64), Location: "https://s3.example.com/b/k", DurationMS: 5, RDB: "VALKEY080", UncompressedBytes: 42},
		"db.restore": db.RestoreResult{Bytes: 10, DurationMS: 5, RDB: "REDIS0011", MovedAside: []string{"dump.rdb.falak-20261006T120000Z"}},
	} {
		sch, err := c.Compile(idBase + "commands/" + typ + ".schema.json#/$defs/result")
		if err != nil {
			t.Fatal(err)
		}
		b, _ := json.Marshal(res)
		v, _ := jsonschema.UnmarshalJSON(bytes.NewReader(b))
		if err := sch.Validate(v); err != nil {
			t.Errorf("%s result invalid: %v\n%s", typ, err, b)
		}
	}
}
