package volumes

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"os"
	"strings"
	"testing"

	"filippo.io/age"

	"github.com/OthmanHaba/falak/agent/internal/backupcrypt"
)

// roomy wires the object store and plenty of free space.
func roomy(st *store) func(*Deps) {
	return func(d *Deps) {
		d.HTTP = st.srv.Client()
		d.StatFS = func(string) (Usage, error) { return Usage{Size: 1 << 30, Available: 1 << 30}, nil }
	}
}

// archived snapshots a docker volume holding two files and returns its result.
func archived(t *testing.T, e *env, st *store, enc backupcrypt.Encryption) ArchiveResult {
	t.Helper()
	ctx := context.Background()
	src := Ref{ID: id1, Kind: KindDocker, Name: "shop_data"}
	if _, err := e.svc.Create(ctx, CreatePayload{Volume: src}, &nopStream{}); err != nil {
		t.Fatal(err)
	}
	write(t, e.fs, "/docker/shop_data/a.txt", "hello")
	write(t, e.fs, "/docker/shop_data/dir/b.txt", strings.Repeat("customer data ", 1000))
	res, err := e.svc.Archive(ctx, ArchivePayload{Encryption: enc, Volume: src, Destination: Location{Kind: "presigned_url", URL: st.url("snap")}}, &nopStream{})
	if err != nil {
		t.Fatal(err)
	}
	return res.(ArchiveResult)
}

func TestArchiveIsEncrypted(t *testing.T) {
	st := newStore(t)
	e := newEnv(t, roomy(st))
	ar := archived(t, e, st, testEnc)
	obj := st.objects["/snap"]
	if !bytes.HasPrefix(obj, []byte("FKB1")) || bytes.Contains(obj, []byte("customer data")) {
		t.Fatal("the snapshot is not encrypted")
	}
	if ar.Encryption != "cp" || ar.KeyID != testEnc.KeyID || ar.Cipher != "aes-256-gcm" || ar.Compression != "zstd" || len(ar.PlaintextSHA256) != 64 {
		t.Fatalf("%+v", ar)
	}
	// The stored file opens with the key, and its trailer holds the tar stream's hash.
	r, err := testEnc.Open(bytes.NewReader(obj))
	if err != nil {
		t.Fatal(err)
	}
	var tarStream bytes.Buffer
	if _, err := tarStream.ReadFrom(r); err != nil {
		t.Fatal(err)
	}
	if sum := sha256.Sum256(tarStream.Bytes()); hex.EncodeToString(sum[:]) != ar.PlaintextSHA256 || int64(tarStream.Len()) != ar.UncompressedBytes {
		t.Fatal("plaintext sha256 / size differ")
	}
}

func TestRestoreRefusesTamperedOrForeignSnapshots(t *testing.T) {
	st := newStore(t)
	e := newEnv(t, roomy(st))
	ar := archived(t, e, st, testEnc)
	ctx := context.Background()

	// Tampered after upload, with a matching sha256 (whoever can write the bucket can't recompute the record, but the
	// cipher is checked even when they could): nothing is written.
	bad := bytes.Clone(st.objects["/snap"])
	bad[len(bad)-30] ^= 1
	st.objects["/bad"] = bad
	sum := sha256.Sum256(bad)
	dst := Ref{ID: id2, Kind: KindDocker, Name: "restored"}
	_, err := e.svc.Restore(ctx, RestorePayload{Encryption: testEnc, Volume: dst, Source: Location{Kind: "url", URL: st.url("bad")}, SHA256: hex.EncodeToString(sum[:])}, &nopStream{})
	if err == nil || !strings.Contains(err.Error(), "corrupt") {
		t.Fatalf("tampered: %v", err)
	}

	// Another key, or another backup's key id: refused before anything is unpacked.
	other := testEnc
	other.Key = strings.Repeat("cd", 32)
	dst2 := Ref{ID: "01j9z8y7x6w5v4t3s2r1q0p9nc", Kind: KindDocker, Name: "restored2"}
	if _, err := e.svc.Restore(ctx, RestorePayload{Encryption: other, Volume: dst2, Source: Location{Kind: "url", URL: st.url("snap")}, SHA256: ar.SHA256}, &nopStream{}); err == nil {
		t.Fatal("wrong key restored")
	}
	other = testEnc
	other.KeyID = "01hzybackup000000000000002"
	if _, err := e.svc.Restore(ctx, RestorePayload{Encryption: other, Volume: dst2, Source: Location{Kind: "url", URL: st.url("snap")}, SHA256: ar.SHA256}, &nopStream{}); err == nil || !strings.Contains(err.Error(), "key id") {
		t.Fatalf("foreign key id: %v", err)
	}
	// The recorded plaintext hash must match.
	dst3 := Ref{ID: "01j9z8y7x6w5v4t3s2r1q0p9nd", Kind: KindDocker, Name: "restored3"}
	if _, err := e.svc.Restore(ctx, RestorePayload{Encryption: testEnc, Volume: dst3, Source: Location{Kind: "url", URL: st.url("snap")}, SHA256: ar.SHA256, PlaintextSHA256: strings.Repeat("0", 64)}, &nopStream{}); err == nil || !strings.Contains(err.Error(), "not the one recorded") {
		t.Fatalf("plaintext sha: %v", err)
	}
}

func TestCustomerHeldSnapshot(t *testing.T) {
	st := newStore(t)
	e := newEnv(t, roomy(st))
	id, _ := age.GenerateX25519Identity()
	ar := archived(t, e, st, backupcrypt.Encryption{Mode: "age", KeyID: "01hzybackup000000000000003", Recipient: id.Recipient().String()})
	if ar.Encryption != "age" {
		t.Fatalf("%+v", ar)
	}
	dst := Ref{ID: id2, Kind: KindDocker, Name: "restored"}
	res, err := e.svc.Restore(context.Background(), RestorePayload{Encryption: backupcrypt.Encryption{Mode: "age", KeyID: "01hzybackup000000000000003", Identity: id.String()},
		Volume: dst, Source: Location{Kind: "url", URL: st.url("snap")}, SHA256: ar.SHA256, PlaintextSHA256: ar.PlaintextSHA256}, &nopStream{})
	if err != nil {
		t.Fatal(err)
	}
	if res.(RestoreResult).Files != 2 {
		t.Fatalf("%+v", res)
	}
}

func TestVolumeDrill(t *testing.T) {
	st := newStore(t)
	e := newEnv(t, func(d *Deps) {
		d.HTTP = st.srv.Client()
		d.StatFS = func(string) (Usage, error) { return Usage{Size: 1 << 30, Available: 1 << 30}, nil }
	})
	ar := archived(t, e, st, testEnc)
	ctx := context.Background()
	p := DrillPayload{Drill: "01hzydrill0000000000000001", Source: Location{Kind: "url", URL: st.url("snap")}, SHA256: ar.SHA256,
		PlaintextSHA256: ar.PlaintextSHA256, ArchiveBytes: ar.SizeBytes, UncompressedBytes: ar.UncompressedBytes, Encryption: testEnc,
		Checks: DrillChecks{Files: ar.Files}}
	res, err := e.svc.Drill(ctx, p, &nopStream{})
	if err != nil {
		t.Fatal(err)
	}
	r := res.(DrillResult)
	if r.Status != "passed" || r.Files != 2 || len(r.Checks) != 2 {
		t.Fatalf("%+v", r)
	}
	scratch := e.fs.P("/var/lib/falak/volumes/.drills/" + p.Drill)
	if _, err := os.Stat(scratch); !os.IsNotExist(err) {
		t.Fatal("the drill's scratch directory was left behind")
	}

	// Fewer files than recorded: failed (not an error), and cleaned up all the same.
	p.Checks.Files = 50
	res, err = e.svc.Drill(ctx, p, &nopStream{})
	if err != nil || res.(DrillResult).Status != "failed" {
		t.Fatalf("%+v %v", res, err)
	}
	// A tampered object fails the restore check.
	bad := bytes.Clone(st.objects["/snap"])
	bad[100] ^= 1
	st.objects["/bad"] = bad
	sum := sha256.Sum256(bad)
	p.Source.URL, p.SHA256, p.Checks.Files = st.url("bad"), hex.EncodeToString(sum[:]), 2
	res, err = e.svc.Drill(ctx, p, &nopStream{})
	if r := res.(DrillResult); err != nil || r.Status != "failed" || r.Checks[0].Name != "restore" {
		t.Fatalf("%+v %v", res, err)
	}
	if _, err := os.Stat(scratch); !os.IsNotExist(err) {
		t.Fatal("scratch left after a failed drill")
	}
	// No room: skipped with the reason.
	e.svc.d.StatFS = func(string) (Usage, error) { return Usage{Available: 10}, nil }
	res, err = e.svc.Drill(ctx, p, &nopStream{})
	if r := res.(DrillResult); err != nil || r.Status != "skipped" || !strings.Contains(r.Reason, "free space") {
		t.Fatalf("%+v %v", res, err)
	}
}
