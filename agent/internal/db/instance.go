package db

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/docker"
)

// stopGrace is how long an engine gets to shut down cleanly.
const stopGrace = 60 * time.Second

// InstancePayload is db.instance.create / db.instance.update.
type InstancePayload struct {
	Instance     InstanceSpec `json:"instance"`
	Password     string       `json:"password"`
	RegistryAuth *docker.Auth `json:"registry_auth,omitempty"`
}

// Secrets are the payload's secret values (masked in output).
func (p InstancePayload) Secrets() []string {
	out := []string{p.Password}
	if p.Instance.TLS != nil {
		out = append(out, p.Instance.TLS.PrivateKey)
	}
	if p.RegistryAuth != nil {
		out = append(out, p.RegistryAuth.Password)
	}
	return out
}

// InstanceResult is their result.
type InstanceResult struct {
	Changed     bool   `json:"changed"`
	ContainerID string `json:"container_id"`
	ImageDigest string `json:"image_digest,omitempty"`
	Health      string `json:"health"`
}

// InstanceCreate pulls the image, creates the container and starts it, then waits until it is healthy.
func (db *DB) InstanceCreate(ctx context.Context, p InstancePayload, st commands.Stream) (any, error) {
	return db.apply(ctx, p, st)
}

// InstanceUpdate converges an instance to the spec: the container is recreated (on the same volume) when anything
// that defines it changed — the settings, too, travel in its environment and are rendered by falak-db at every start.
func (db *DB) InstanceUpdate(ctx context.Context, p InstancePayload, st commands.Stream) (any, error) {
	return db.apply(ctx, p, st)
}

func (db *DB) apply(ctx context.Context, p InstancePayload, st commands.Stream) (any, error) {
	s := p.Instance
	if err := s.validate(); err != nil {
		return nil, err
	}
	if p.Password == "" {
		return nil, payloadErr("password is required")
	}
	name := Container(s.ID)
	if err := db.awaitVolume(ctx, s.VolumeID, st); err != nil {
		return nil, err
	}
	vol := db.d.FS.P(db.volumeDir(s.VolumeID))
	for _, sub := range []string{"data", "spool"} {
		if err := os.MkdirAll(filepath.Join(vol, sub), 0o700); err != nil {
			return nil, err
		}
	}
	digest, err := db.ensureImage(ctx, s, p.RegistryAuth, st)
	if err != nil {
		return nil, err
	}
	dir, _, err := db.ensureSecretsDir(s.ID)
	if err != nil {
		return nil, err
	}
	if err := writeSecret(dir, "password", []byte(p.Password)); err != nil {
		return nil, fmt.Errorf("password file: %w", err)
	}
	tlsFP, err := db.writeTLS(s)
	if err != nil {
		return nil, err
	}
	if s.Network != "" {
		if err := db.ensureNetwork(ctx, s.Network); err != nil {
			return nil, err
		}
	}

	hash := s.hash(tlsFP)
	cur, exists, err := db.d.Docker.ContainerInspect(ctx, name)
	if err != nil {
		return nil, err
	}
	// A tag pulled again may point at a newer image (a minor upgrade): the container then runs it.
	sameImage := true
	if exists && s.Digest == "" {
		if imageID, ok, err := db.d.Docker.ImageInspect(ctx, s.ref()); err == nil && ok {
			sameImage = imageID == cur.Image
		}
	}
	if exists && sameImage && cur.Config.Labels[docker.LabelSpecHash] == hash {
		changed := false
		if !cur.State.Running {
			if err := db.d.Docker.ContainerStart(ctx, cur.ID); err != nil {
				return nil, err
			}
			changed = true
		}
		health, err := db.awaitHealthy(ctx, name)
		return InstanceResult{Changed: changed, ContainerID: cur.ID, ImageDigest: digest, Health: health}, err
	}
	if exists {
		fmt.Fprintf(st.Stdout(), "recreating %s (its spec changed)\n", name)
		if _, err := db.d.Docker.ContainerStop(ctx, cur.ID, stopGrace); err != nil && !docker.IsNotFound(err) {
			return nil, err
		}
		if err := db.d.Docker.ContainerRemove(ctx, cur.ID); err != nil {
			return nil, err
		}
	}
	id, err := db.d.Docker.ContainerCreate(ctx, name, db.createBody(s, tlsFP))
	if err != nil {
		return nil, err
	}
	if err := db.d.Docker.ContainerStart(ctx, id); err != nil {
		return nil, err
	}
	fmt.Fprintf(st.Stdout(), "started %s (%s %s)\n", name, s.Engine, s.Version)
	health, err := db.awaitHealthy(ctx, name)
	return InstanceResult{Changed: true, ContainerID: id, ImageDigest: digest, Health: health}, err
}

// awaitVolume waits for the instance's sized volume to be mounted (volume.create runs concurrently with the first
// db.instance.create).
func (db *DB) awaitVolume(ctx context.Context, volumeID string, st commands.Stream) error {
	path := db.d.FS.P(db.volumeDir(volumeID))
	deadline := time.Now().Add(db.d.VolumeWait)
	for said := false; !db.d.Mounted(path); said = true {
		if time.Now().After(deadline) {
			return fmt.Errorf("volume %s is not mounted at %s", volumeID, db.volumeDir(volumeID))
		}
		if !said {
			fmt.Fprintf(st.Stdout(), "waiting for volume %s\n", volumeID)
		}
		if err := sleep(ctx, db.d.Poll); err != nil {
			return err
		}
	}
	return nil
}

// ensureImage pulls the instance's image when it is missing and returns its digest. A pinned digest is pulled by
// digest and checked against the image's RepoDigests.
//
// TODO(v0.10 step 3): verify the image's cosign signature (keyless, GitHub OIDC; docs/DB_IMAGES.md) before it runs.
// Today the digest pin is the only check.
func (db *DB) ensureImage(ctx context.Context, s InstanceSpec, auth *docker.Auth, st commands.Stream) (string, error) {
	ref := s.ref()
	digests, ok, err := db.d.Docker.ImageRepoDigests(ctx, ref)
	if err != nil {
		return "", err
	}
	// A pinned digest never changes; a tag is pulled again every time (a rebuilt image is a minor upgrade).
	if !ok || s.Digest == "" {
		fmt.Fprintf(st.Stdout(), "pulling %s\n", ref)
		if err := db.d.Docker.ImagePull(ctx, ref, auth, st.Stdout()); err != nil {
			return "", fmt.Errorf("pull %s: %w", ref, err)
		}
		if digests, ok, err = db.d.Docker.ImageRepoDigests(ctx, ref); err != nil || !ok {
			return "", fmt.Errorf("image %s missing after the pull: %v", ref, err)
		}
	}
	repo := repository(s.Image)
	if s.Digest != "" {
		if !slices.Contains(digests, repo+"@"+s.Digest) {
			return "", fmt.Errorf("image %s does not carry the pinned digest %s", ref, s.Digest)
		}
		return s.Digest, nil
	}
	for _, d := range digests {
		if r, dg, ok := strings.Cut(d, "@"); ok && r == repo {
			return dg, nil
		}
	}
	return "", nil
}

// writeTLS installs the instance's certificate (/etc/falak/db/<id>/tls, root only, mounted read-only) when the payload
// has one, and returns the fingerprint of what is installed ("" when nothing is: TLS off).
func (db *DB) writeTLS(s InstanceSpec) (string, error) {
	dir := db.d.FS.P(db.tlsDir(s.ID))
	if s.TLS == nil {
		return tlsFingerprint(dir), nil
	}
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return "", err
	}
	for name, content := range map[string]string{"server.crt": s.TLS.Certificate, "server.key": s.TLS.PrivateKey, "ca.crt": s.TLS.CA} {
		p := filepath.Join(dir, name)
		if content == "" {
			_ = os.Remove(p)
			continue
		}
		if err := os.WriteFile(p+".tmp", []byte(content), 0o600); err != nil {
			return "", err
		}
		if err := os.Rename(p+".tmp", p); err != nil {
			return "", err
		}
	}
	return tlsFingerprint(dir), nil
}

// tlsFingerprint hashes the installed certificate files ("" without a certificate and key).
func tlsFingerprint(dir string) string {
	h := sha256.New()
	for _, name := range []string{"server.crt", "server.key", "ca.crt"} {
		b, err := os.ReadFile(filepath.Join(dir, name))
		if err != nil && name != "ca.crt" {
			return ""
		}
		h.Write([]byte(name + "\x00"))
		h.Write(b)
	}
	return hex.EncodeToString(h.Sum(nil))
}

// ensureNetwork creates an environment's network when missing.
func (db *DB) ensureNetwork(ctx context.Context, name string) error {
	ok, err := db.d.Docker.NetworkExists(ctx, name)
	if err != nil || ok {
		return err
	}
	return db.d.Docker.NetworkCreate(ctx, name, map[string]string{docker.LabelManaged: "true", "falak.network": "environment"})
}

// awaitHealthy waits for the container's healthcheck; a container that stops (or stays unhealthy) fails with the end
// of its log.
func (db *DB) awaitHealthy(ctx context.Context, name string) (string, error) {
	deadline := time.Now().Add(db.d.HealthWait)
	for {
		c, ok, err := db.d.Docker.ContainerInspect(ctx, name)
		if err != nil {
			return "", err
		}
		if !ok {
			return "", fmt.Errorf("%s is gone", name)
		}
		health := healthOf(c)
		switch {
		case health == "healthy" || health == "none" && c.State.Running:
			return health, nil
		case !c.State.Running && c.State.Status != "restarting":
			return health, fmt.Errorf("%s stopped (exit %d): %s", name, c.State.ExitCode, db.logTail(ctx, name))
		case time.Now().After(deadline):
			return health, fmt.Errorf("%s is not healthy after %s (%s): %s", name, db.d.HealthWait, health, db.logTail(ctx, name))
		}
		if err := sleep(ctx, db.d.Poll); err != nil {
			return health, err
		}
	}
}

func healthOf(c *docker.Container) string {
	if c.State.Health == nil || c.State.Health.Status == "" {
		return "none"
	}
	return c.State.Health.Status
}

// logTail is the end of a container's log (for errors; the engines never log passwords).
func (db *DB) logTail(ctx context.Context, name string) string {
	var b strings.Builder
	_ = db.d.Docker.ContainerLogs(ctx, name, false, 20, &b)
	return lastLines(b.String(), 8)
}

// ---- restart / stop / delete ----

// IDPayload names an instance.
type IDPayload struct {
	ID string `json:"id"`
}

// ChangedResult is {changed}.
type ChangedResult struct {
	Changed bool   `json:"changed"`
	Health  string `json:"health,omitempty"`
}

// InstanceRestart restarts the container (60 s to shut down) and waits until it is healthy.
func (db *DB) InstanceRestart(ctx context.Context, p IDPayload, _ commands.Stream) (any, error) {
	if err := checkID("id", p.ID); err != nil {
		return nil, err
	}
	if err := db.d.Docker.ContainerRestart(ctx, Container(p.ID), stopGrace); err != nil {
		return nil, err
	}
	health, err := db.awaitHealthy(ctx, Container(p.ID))
	return ChangedResult{Changed: true, Health: health}, err
}

// InstanceStop stops the container (it stays, with restart unless-stopped keeping it down).
func (db *DB) InstanceStop(ctx context.Context, p IDPayload, _ commands.Stream) (any, error) {
	if err := checkID("id", p.ID); err != nil {
		return nil, err
	}
	stopped, err := db.d.Docker.ContainerStop(ctx, Container(p.ID), stopGrace)
	if docker.IsNotFound(err) {
		return ChangedResult{}, nil
	}
	return ChangedResult{Changed: stopped}, err
}

// InstanceDelete removes the container, its secret files and its certificate. The data volume is the control plane's
// (volume.delete).
func (db *DB) InstanceDelete(ctx context.Context, p IDPayload, st commands.Stream) (any, error) {
	if err := checkID("id", p.ID); err != nil {
		return nil, err
	}
	name := Container(p.ID)
	cur, exists, err := db.d.Docker.ContainerInspect(ctx, name)
	if err != nil {
		return nil, err
	}
	if exists {
		if _, err := db.d.Docker.ContainerStop(ctx, cur.ID, stopGrace); err != nil && !docker.IsNotFound(err) {
			return nil, err
		}
		if err := db.d.Docker.ContainerRemove(ctx, cur.ID); err != nil {
			return nil, err
		}
		fmt.Fprintf(st.Stdout(), "removed %s\n", name)
	}
	dir := db.d.FS.P(db.secretsDir(p.ID))
	_ = os.Chmod(dir, 0o700)
	if err := os.RemoveAll(dir); err != nil {
		return nil, err
	}
	if err := os.RemoveAll(filepath.Dir(db.d.FS.P(db.tlsDir(p.ID)))); err != nil {
		return nil, err
	}
	return ChangedResult{Changed: exists}, nil
}

// ---- password / secrets ----

// PasswordPayload is db.instance.password and db.instance.secrets.
type PasswordPayload struct {
	ID       string `json:"id"`
	Engine   string `json:"engine"`
	Password string `json:"password"`
}

// Secrets are the payload's secret values.
func (p PasswordPayload) Secrets() []string { return []string{p.Password} }

func (p PasswordPayload) validate() error {
	if err := checkID("id", p.ID); err != nil {
		return err
	}
	if err := checkEngine(p.Engine); err != nil {
		return err
	}
	if p.Password == "" {
		return payloadErr("password is required")
	}
	return nil
}

// InstancePassword rotates the superuser / root / default password: falak-db sets the new one (connecting with the
// current file), then the new file replaces the current one so the next start uses it.
func (db *DB) InstancePassword(ctx context.Context, p PasswordPayload, _ commands.Stream) (any, error) {
	if err := p.validate(); err != nil {
		return nil, err
	}
	dir := db.d.FS.P(db.secretsDir(p.ID))
	if _, err := os.Stat(dir); err != nil {
		return nil, fmt.Errorf("the secret files of %s are missing (restore them first): %w", Container(p.ID), err)
	}
	const next = ".password.new"
	if err := writeSecret(dir, next, []byte(p.Password)); err != nil {
		return nil, err
	}
	if _, _, err := db.exec(ctx, p.ID, nil, nil, nil, "password", "set", "--file", secretsTarget+"/"+next); err != nil {
		removeSecret(dir, next)
		return nil, err
	}
	if err := writable(dir, func() error { return os.Rename(filepath.Join(dir, next), filepath.Join(dir, "password")) }); err != nil {
		return nil, err
	}
	return ChangedResult{Changed: true}, nil
}

// SecretsResult is db.instance.secrets' result.
type SecretsResult struct {
	Restored bool `json:"restored"`
	Started  bool `json:"started"`
}

// InstanceSecrets puts the password file back after a reboot emptied /run (the container could not start without
// its secrets directory) and starts the container. A present directory is left alone.
func (db *DB) InstanceSecrets(ctx context.Context, p PasswordPayload, st commands.Stream) (any, error) {
	if err := p.validate(); err != nil {
		return nil, err
	}
	name := Container(p.ID)
	cur, exists, err := db.d.Docker.ContainerInspect(ctx, name)
	if err != nil {
		return nil, err
	}
	if !exists {
		return nil, fmt.Errorf("%s does not exist", name)
	}
	var res SecretsResult
	dir, created, err := db.ensureSecretsDir(p.ID)
	if err != nil {
		return nil, err
	}
	if created {
		if err := writeSecret(dir, "password", []byte(p.Password)); err != nil {
			return nil, err
		}
		res.Restored = true
		fmt.Fprintf(st.Stdout(), "secret files of %s written\n", name)
	}
	if !cur.State.Running {
		if err := db.d.Docker.ContainerStart(ctx, cur.ID); err != nil {
			return res, fmt.Errorf("starting %s: %w", name, err)
		}
		res.Started = true
	}
	return res, nil
}

// ---- major upgrade ----

// UpgradeEnd is the source or target instance of an upgrade.
type UpgradeEnd struct {
	ID     string `json:"id"`
	Engine string `json:"engine"`
}

// UpgradeDatabase is a database copied by an upgrade.
type UpgradeDatabase struct {
	Name      string `json:"name"`
	Charset   string `json:"charset,omitempty"`
	Collation string `json:"collation,omitempty"`
}

// UpgradePayload is db.instance.upgrade (major versions of the SQL engines: a new instance, filled by dump and
// restore, takes over the old one's DNS name).
type UpgradePayload struct {
	Mode      string            `json:"mode"`
	Source    UpgradeEnd        `json:"source"`
	Target    UpgradeEnd        `json:"target"`
	Databases []UpgradeDatabase `json:"databases"`
	Users     []UserSpec        `json:"users"`
	Network   string            `json:"network,omitempty"`
	Alias     string            `json:"alias"`
}

// Secrets are the users' passwords.
func (p UpgradePayload) Secrets() []string {
	var out []string
	for _, u := range p.Users {
		out = append(out, u.Password)
	}
	return out
}

// UpgradeResult is its result.
type UpgradeResult struct {
	Databases  []CopiedDatabase `json:"databases"`
	DurationMS int64            `json:"duration_ms"`
}

// CopiedDatabase is one database moved by an upgrade.
type CopiedDatabase struct {
	Name  string `json:"name"`
	Bytes int64  `json:"bytes"`
}

// InstanceUpgrade copies every database from the source to the target instance (falak-db backup logical piped into
// restore logical), applies the users there, moves the DNS alias to the target and stops the source.
func (db *DB) InstanceUpgrade(ctx context.Context, p UpgradePayload, st commands.Stream) (any, error) {
	start := time.Now()
	if p.Mode != "major" {
		return nil, payloadErr("unknown mode %q", p.Mode)
	}
	for _, e := range []UpgradeEnd{p.Source, p.Target} {
		if err := checkID("id", e.ID); err != nil {
			return nil, err
		}
		if err := checkEngine(e.Engine); err != nil {
			return nil, err
		}
	}
	if p.Source.ID == p.Target.ID || isKeyValue(p.Source.Engine) || p.Source.Engine != p.Target.Engine {
		return nil, payloadErr("a major upgrade copies between two instances of one SQL engine")
	}
	if p.Network != "" && !envNetRe.MatchString(p.Network) {
		return nil, payloadErr("invalid network %q", p.Network)
	}
	if !aliasRe.MatchString(p.Alias) {
		return nil, payloadErr("invalid alias %q", p.Alias)
	}
	res := UpgradeResult{Databases: []CopiedDatabase{}}
	for _, d := range p.Databases {
		if err := checkIdent("database", d.Name); err != nil {
			return nil, err
		}
		args := []string{"database", "create", "--name", d.Name}
		if d.Charset != "" {
			args = append(args, "--charset", d.Charset)
		}
		if d.Collation != "" {
			args = append(args, "--collation", d.Collation)
		}
		if _, _, err := db.exec(ctx, p.Target.ID, nil, nil, nil, args...); err != nil {
			return nil, err
		}
		n, err := db.copyDatabase(ctx, p.Source.ID, p.Target.ID, d.Name)
		if err != nil {
			return nil, err
		}
		fmt.Fprintf(st.Stdout(), "copied %s: %d bytes\n", d.Name, n)
		res.Databases = append(res.Databases, CopiedDatabase{Name: d.Name, Bytes: n})
	}
	for _, u := range p.Users {
		if _, err := db.userApply(ctx, p.Target.ID, u); err != nil {
			return nil, err
		}
	}
	if p.Network != "" {
		if err := db.d.Docker.NetworkDisconnect(ctx, p.Network, Container(p.Target.ID)); err != nil {
			return nil, err
		}
		if err := db.d.Docker.NetworkConnect(ctx, p.Network, Container(p.Target.ID), []string{p.Alias, Container(p.Target.ID)}); err != nil {
			return nil, err
		}
		if err := db.d.Docker.NetworkDisconnect(ctx, p.Network, Container(p.Source.ID)); err != nil {
			return nil, err
		}
		fmt.Fprintf(st.Stdout(), "%s now answers as %s\n", Container(p.Target.ID), p.Alias)
	}
	if _, err := db.d.Docker.ContainerStop(ctx, Container(p.Source.ID), stopGrace); err != nil && !docker.IsNotFound(err) {
		return nil, err
	}
	res.DurationMS = time.Since(start).Milliseconds()
	return res, nil
}

// copyDatabase pipes a logical backup of the source into a restore on the target and returns the bytes moved.
func (db *DB) copyDatabase(ctx context.Context, source, target, name string) (int64, error) {
	pr, pw := io.Pipe()
	cw := &countingWriter{w: pw}
	errSrc := make(chan error, 1)
	go func() {
		_, _, err := db.exec(ctx, source, nil, cw, nil, "backup", "logical", "--database", name, "--out", "-")
		pw.CloseWithError(err)
		errSrc <- err
	}()
	_, _, errDst := db.exec(ctx, target, pr, nil, nil, "restore", "logical", "--database", name, "--in", "-")
	pr.CloseWithError(io.ErrClosedPipe)
	errS := <-errSrc
	if errS != nil {
		return 0, fmt.Errorf("dump %s: %w", name, errS)
	}
	if errDst != nil {
		return 0, fmt.Errorf("restore %s: %w", name, errDst)
	}
	return cw.n, nil
}
