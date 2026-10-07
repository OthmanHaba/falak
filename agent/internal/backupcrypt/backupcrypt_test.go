package backupcrypt

import (
	"bytes"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/binary"
	"encoding/hex"
	"errors"
	"io"
	"strings"
	"testing"

	"filippo.io/age"
)

func testKey(t testing.TB) []byte {
	t.Helper()
	k := make([]byte, KeyBytes)
	if _, err := rand.Read(k); err != nil {
		t.Fatal(err)
	}
	return k
}

func seal(t testing.TB, key, data []byte, o Options) []byte {
	t.Helper()
	if o.Mode == 0 {
		o.Mode = ModeCP
	}
	if o.KeyID == "" {
		o.KeyID = "01jbackup0000000000000000a"
	}
	var b bytes.Buffer
	w, err := NewWriter(&b, key, o)
	if err != nil {
		t.Fatal(err)
	}
	// Uneven writes cross segment boundaries.
	for len(data) > 0 {
		n := min(len(data), 7777)
		if _, err := w.Write(data[:n]); err != nil {
			t.Fatal(err)
		}
		data = data[n:]
	}
	if err := w.Close(); err != nil {
		t.Fatal(err)
	}
	return b.Bytes()
}

func open(key, file []byte) ([]byte, Summary, error) {
	r, err := NewReader(bytes.NewReader(file), key)
	if err != nil {
		return nil, Summary{}, err
	}
	defer r.Close()
	out, err := io.ReadAll(r)
	return out, r.Summary(), err
}

// incompressible data, so segments fill up.
func randomData(t testing.TB, n int) []byte {
	b := make([]byte, n)
	if _, err := rand.Read(b); err != nil {
		t.Fatal(err)
	}
	return b
}

// segments splits a file into its header and records (length prefix included).
func segments(t *testing.T, file []byte) (header []byte, recs [][]byte) {
	t.Helper()
	r := bytes.NewReader(file)
	if _, err := ReadHeader(r); err != nil {
		t.Fatal(err)
	}
	hl := len(file) - r.Len()
	header = file[:hl]
	rest := file[hl:]
	for len(rest) > 0 {
		l := binary.BigEndian.Uint32(rest[:4]) &^ lastFlag
		n := 4 + int(l) + tagBytes
		recs = append(recs, rest[:n])
		rest = rest[n:]
	}
	return header, recs
}

func TestRoundTrip(t *testing.T) {
	key := testKey(t)
	for _, size := range []int{0, 1, 100, SegmentSize - 1, SegmentSize, SegmentSize + 1, 5*SegmentSize + 3} {
		for name, data := range map[string][]byte{"random": randomData(t, size), "text": bytes.Repeat([]byte("falak "), size/6+1)[:size]} {
			file := seal(t, key, data, Options{})
			got, sum, err := open(key, file)
			if err != nil {
				t.Fatalf("%s %d: %v", name, size, err)
			}
			if !bytes.Equal(got, data) {
				t.Fatalf("%s %d: data differs", name, size)
			}
			if sum.Bytes != int64(size) || sum.SHA256 != sha256.Sum256(data) {
				t.Fatalf("%s %d: summary %+v", name, size, sum)
			}
		}
	}
}

func TestWriterSummary(t *testing.T) {
	key := testKey(t)
	data := []byte("hello backup")
	var b bytes.Buffer
	w, err := NewWriter(&b, key, Options{Mode: ModeCP, KeyID: "k1"})
	if err != nil {
		t.Fatal(err)
	}
	w.Write(data)
	if err := w.Close(); err != nil {
		t.Fatal(err)
	}
	if s := w.Summary(); s.Bytes != int64(len(data)) || s.SHA256 != sha256.Sum256(data) {
		t.Fatalf("summary %+v", s)
	}
	if _, err := w.Write([]byte("x")); err == nil {
		t.Fatal("write after close accepted")
	}
}

func TestCompresses(t *testing.T) {
	data := bytes.Repeat([]byte("INSERT INTO users VALUES (1, 'a');\n"), 20000)
	file := seal(t, testKey(t), data, Options{})
	if len(file) > len(data)/10 {
		t.Fatalf("%d bytes for %d of repetitive data: not compressed", len(file), len(data))
	}
}

func TestTamperAnyByte(t *testing.T) {
	key := testKey(t)
	data := randomData(t, 3*1024+17)
	file := seal(t, key, data, Options{SegmentSize: 1024})
	for i := range file {
		bad := bytes.Clone(file)
		bad[i] ^= 0x01
		got, _, err := open(key, bad)
		if err == nil {
			t.Fatalf("byte %d flipped: opened", i)
		}
		// Nothing unauthenticated may come out: what was returned is a prefix of the data.
		if !bytes.HasPrefix(data, got) {
			t.Fatalf("byte %d flipped: returned data that is not the original's", i)
		}
	}
}

func TestTruncation(t *testing.T) {
	key := testKey(t)
	data := randomData(t, 4096+100)
	file := seal(t, key, data, Options{SegmentSize: 1024})
	header, recs := segments(t, file)
	if len(recs) < 3 {
		t.Fatalf("%d records", len(recs))
	}
	cases := map[string][]byte{
		"no trailer":       bytes.Join(append([][]byte{header}, recs[:len(recs)-1]...), nil),
		"last data gone":   bytes.Join(append(append([][]byte{header}, recs[:len(recs)-2]...), recs[len(recs)-1]), nil),
		"header only":      header,
		"half a header":    header[:len(header)/2],
		"empty":            nil,
		"one byte short":   file[:len(file)-1],
		"mid segment":      file[:len(header)+500],
		"trailing garbage": append(bytes.Clone(file), 0),
	}
	for name, f := range cases {
		if _, _, err := open(key, f); err == nil {
			t.Errorf("%s: opened", name)
		}
	}
}

func TestReorderAndDuplicate(t *testing.T) {
	key := testKey(t)
	file := seal(t, key, randomData(t, 4096), Options{SegmentSize: 1024})
	header, recs := segments(t, file)
	swapped := append([][]byte{header, recs[1], recs[0]}, recs[2:]...)
	if _, _, err := open(key, bytes.Join(swapped, nil)); !errors.Is(err, ErrAuth) {
		t.Fatalf("reordered: %v", err)
	}
	dup := append([][]byte{header, recs[0], recs[0]}, recs[1:]...)
	if _, _, err := open(key, bytes.Join(dup, nil)); !errors.Is(err, ErrAuth) {
		t.Fatalf("duplicated: %v", err)
	}
	// A segment of another file with the same key.
	other := seal(t, key, randomData(t, 4096), Options{SegmentSize: 1024})
	_, orecs := segments(t, other)
	mixed := append([][]byte{header, orecs[0]}, recs[1:]...)
	if _, _, err := open(key, bytes.Join(mixed, nil)); !errors.Is(err, ErrAuth) {
		t.Fatalf("spliced: %v", err)
	}
	// The trailer flag moved onto a data segment.
	flag := bytes.Clone(recs[0])
	binary.BigEndian.PutUint32(flag, binary.BigEndian.Uint32(flag)|lastFlag)
	if _, _, err := open(key, bytes.Join(append([][]byte{header, flag}, recs[1:]...), nil)); err == nil {
		t.Fatal("flagged data segment opened")
	}
}

func TestWrongKey(t *testing.T) {
	file := seal(t, testKey(t), []byte("secret rows"), Options{})
	if _, _, err := open(testKey(t), file); !errors.Is(err, ErrAuth) {
		t.Fatalf("wrong key: %v", err)
	}
	if _, err := NewReader(bytes.NewReader(file), []byte("short")); err == nil {
		t.Fatal("short key accepted")
	}
}

func TestHeaderTamper(t *testing.T) {
	key := testKey(t)
	file := seal(t, key, []byte("data"), Options{KeyID: "backup-a"})
	// The key id is authenticated (AAD): renaming the file's backup fails even with the right key.
	bad := bytes.Replace(file, []byte("backup-a"), []byte("backup-b"), 1)
	if _, _, err := open(key, bad); !errors.Is(err, ErrAuth) {
		t.Fatalf("renamed key id: %v", err)
	}
	for _, c := range []struct {
		at  int
		val byte
	}{{0, 'X'}, {4, 2}, {5, 9}, {6, 0}, {7, 3}} {
		bad := bytes.Clone(file)
		bad[c.at] = c.val
		if _, _, err := open(key, bad); !errors.Is(err, ErrFormat) {
			t.Errorf("header byte %d = %d: %v", c.at, c.val, err)
		}
	}
	// A larger segment size passes the header checks but not authentication.
	bad = bytes.Clone(file)
	bad[10] = 0x02
	if _, _, err := open(key, bad); err == nil {
		t.Fatal("segment size changed: opened")
	}
}

func TestLargeStream(t *testing.T) {
	if testing.Short() {
		t.Skip("large")
	}
	key := testKey(t)
	const size = 64 << 20
	src := io.LimitReader(rand.Reader, size)
	h := sha256.New()
	pr, pw := io.Pipe()
	go func() {
		w, err := NewWriter(pw, key, Options{Mode: ModeCP, KeyID: "big"})
		if err != nil {
			pw.CloseWithError(err)
			return
		}
		_, err = io.Copy(w, io.TeeReader(src, h))
		if err == nil {
			err = w.Close()
		}
		pw.CloseWithError(err)
	}()
	r, err := NewReader(pr, key)
	if err != nil {
		t.Fatal(err)
	}
	h2 := sha256.New()
	n, err := io.Copy(h2, r)
	if err != nil {
		t.Fatal(err)
	}
	if n != size || !bytes.Equal(h.Sum(nil), h2.Sum(nil)) || r.Summary().Bytes != size {
		t.Fatalf("%d bytes, summary %+v", n, r.Summary())
	}
}

func TestAgeMode(t *testing.T) {
	id, err := age.GenerateX25519Identity()
	if err != nil {
		t.Fatal(err)
	}
	enc := Encryption{Mode: "age", KeyID: "01jbackup", Recipient: id.Recipient().String()}
	var b bytes.Buffer
	w, err := enc.Seal(&b)
	if err != nil {
		t.Fatal(err)
	}
	data := []byte(strings.Repeat("customer data\n", 1000))
	w.Write(data)
	if err := w.Close(); err != nil {
		t.Fatal(err)
	}
	h, err := ReadHeader(bytes.NewReader(b.Bytes()))
	if err != nil || h.Mode != ModeAge || len(h.Wrapped) == 0 {
		t.Fatalf("header %+v %v", h, err)
	}
	// The wrapped key is a plain age file: the age tools open it too.
	if _, err := age.Decrypt(bytes.NewReader(h.Wrapped), id); err != nil {
		t.Fatal(err)
	}
	r, err := Encryption{Mode: "age", KeyID: "01jbackup", Identity: id.String()}.Open(bytes.NewReader(b.Bytes()))
	if err != nil {
		t.Fatal(err)
	}
	got, err := io.ReadAll(r)
	if err != nil || !bytes.Equal(got, data) {
		t.Fatalf("age round trip: %v", err)
	}
	other, _ := age.GenerateX25519Identity()
	if _, err := (Encryption{Mode: "age", KeyID: "01jbackup", Identity: other.String()}).Open(bytes.NewReader(b.Bytes())); err == nil || !strings.Contains(err.Error(), "does not match") {
		t.Fatalf("other identity: %v", err)
	}
	// A cp-mode key can't open an age backup, and the key id must match.
	if _, err := (Encryption{Mode: "cp", KeyID: "01jbackup", Key: base64.StdEncoding.EncodeToString(testKey(t))}).Open(bytes.NewReader(b.Bytes())); err == nil {
		t.Fatal("cp key opened an age backup")
	}
	if _, err := (Encryption{Mode: "age", KeyID: "01jother", Identity: id.String()}).Open(bytes.NewReader(b.Bytes())); err == nil {
		t.Fatal("another backup's key id accepted")
	}
}

func TestEncryptionCheck(t *testing.T) {
	id, _ := age.GenerateX25519Identity()
	key := base64.StdEncoding.EncodeToString(make([]byte, 32))
	ok := []Encryption{
		{Mode: "cp", KeyID: "a", Key: key},
		{Mode: "age", KeyID: "a", Recipient: id.Recipient().String()},
	}
	for _, e := range ok {
		if err := e.Check(true); err != nil {
			t.Errorf("%+v: %v", e, err)
		}
	}
	bad := []Encryption{
		{Mode: "cp", KeyID: "a", Key: base64.StdEncoding.EncodeToString(make([]byte, 16))},
		{Mode: "cp", KeyID: "", Key: key},
		{Mode: "cp", KeyID: "a b", Key: key},
		{Mode: "cp", KeyID: "a", Key: key, Recipient: id.Recipient().String()},
		{Mode: "age", KeyID: "a", Recipient: "age1notarecipient"},
		{Mode: "age", KeyID: "a", Recipient: id.Recipient().String(), Key: key},
		{Mode: "none", KeyID: "a"},
	}
	for _, e := range bad {
		if err := e.Check(true); err == nil {
			t.Errorf("%+v accepted", e)
		}
	}
	if err := (Encryption{Mode: "age", KeyID: "a", Identity: "AGE-SECRET-KEY-1NOPE"}).Check(false); err == nil {
		t.Error("bad identity accepted")
	}
	if s := (Encryption{Key: "k", Identity: "i"}).Secrets(); len(s) != 2 || s[0] != "k" || s[1] != "i" {
		t.Errorf("secrets %v", s)
	}
}

func TestDecodeKey(t *testing.T) {
	k := testKey(t)
	for _, s := range []string{hex.EncodeToString(k), base64.StdEncoding.EncodeToString(k), base64.RawURLEncoding.EncodeToString(k), " " + hex.EncodeToString(k) + "\n"} {
		got, err := DecodeKey(s)
		if err != nil || !bytes.Equal(got, k) {
			t.Errorf("%q: %v", s, err)
		}
	}
	for _, s := range []string{"", "zz", hex.EncodeToString(k[:16])} {
		if _, err := DecodeKey(s); err == nil {
			t.Errorf("%q accepted", s)
		}
	}
}

func TestNotFKB1(t *testing.T) {
	if _, err := NewReader(strings.NewReader("\x1f\x8b gzip data, not a backup"), testKey(t)); !errors.Is(err, ErrFormat) {
		t.Fatalf("gzip: %v", err)
	}
}

func FuzzOpen(f *testing.F) {
	key := make([]byte, KeyBytes)
	f.Add(seal(f, key, []byte("seed data"), Options{SegmentSize: 16}))
	f.Add(seal(f, key, nil, Options{}))
	f.Add([]byte("FKB1"))
	f.Fuzz(func(t *testing.T, file []byte) {
		// Never panics; whatever opens must have been authenticated against the trailer.
		r, err := NewReader(bytes.NewReader(file), key)
		if err != nil {
			return
		}
		out, err := io.ReadAll(r)
		if err == nil && (r.Summary().Bytes != int64(len(out)) || r.Summary().SHA256 != sha256.Sum256(out)) {
			t.Fatal("opened without a matching trailer")
		}
	})
}

func FuzzRoundTrip(f *testing.F) {
	f.Add([]byte(""), uint16(16))
	f.Add([]byte("some data to back up"), uint16(3))
	f.Fuzz(func(t *testing.T, data []byte, seg uint16) {
		key := make([]byte, KeyBytes)
		file := seal(t, key, data, Options{SegmentSize: int(seg%4096) + 1})
		got, _, err := open(key, file)
		if err != nil || !bytes.Equal(got, data) {
			t.Fatalf("round trip: %v", err)
		}
	})
}
