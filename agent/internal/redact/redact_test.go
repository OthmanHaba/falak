package redact

import (
	"bytes"
	"context"
	"encoding/base64"
	"net/url"
	"strings"
	"sync"
	"testing"
)

const secret = "s3cr3t-Pa$$/w0rd+="

func TestStringMasksEveryForm(t *testing.T) {
	s := NewSet(secret)
	for name, form := range map[string]string{
		"raw":         secret,
		"base64":      base64.StdEncoding.EncodeToString([]byte(secret)),
		"base64url":   base64.URLEncoding.EncodeToString([]byte(secret)),
		"base64 bare": base64.RawStdEncoding.EncodeToString([]byte(secret)),
		"query":       url.QueryEscape(secret),
		"path":        url.PathEscape(secret),
	} {
		got := s.String("before " + form + " after")
		if strings.Contains(got, form) || !strings.Contains(got, Mask) {
			t.Errorf("%s: %q not masked: %q", name, form, got)
		}
		if !strings.HasPrefix(got, "before ") || !strings.HasSuffix(got, " after") {
			t.Errorf("%s: surrounding text changed: %q", name, got)
		}
	}
}

func TestShortValuesAreIgnored(t *testing.T) {
	s := NewSet("true", "3306", "abcde")
	if !s.Empty() {
		t.Fatal("values under MinLen must not be masked")
	}
	if got := s.String("true 3306 abcde"); got != "true 3306 abcde" {
		t.Fatalf("got %q", got)
	}
	var nilSet *Set
	if got := nilSet.String("abcdefgh"); got != "abcdefgh" {
		t.Fatalf("nil set changed output: %q", got)
	}
}

func TestOverlappingSecretsLeaveNothing(t *testing.T) {
	s := NewSet("abcdefgh", "efghijkl")
	if got := s.String("x abcdefghijkl y"); got != "x "+Mask+" y" {
		t.Fatalf("got %q", got)
	}
	// One secret inside another.
	s = NewSet("password123", "word12")
	if got := s.String("[password123][word12]"); got != "["+Mask+"]["+Mask+"]" {
		t.Fatalf("got %q", got)
	}
}

// chunked writes data through a Writer split at every position, and returns each result.
func TestWriterMasksAcrossEveryChunkBoundary(t *testing.T) {
	s := NewSet(secret, "another-secret")
	b64 := base64.StdEncoding.EncodeToString([]byte(secret))
	in := "line one " + secret + "\nauth: " + b64 + " end another-secret!\n"
	want := s.String(in)
	if strings.Contains(want, secret) || strings.Contains(want, "another-secret") {
		t.Fatalf("whole-string masking failed: %q", want)
	}
	for i := 0; i <= len(in); i++ {
		for j := i; j <= len(in); j += 7 {
			var out bytes.Buffer
			w := NewWriter(s, &out)
			for _, part := range []string{in[:i], in[i:j], in[j:]} {
				if n, err := w.Write([]byte(part)); err != nil || n != len(part) {
					t.Fatalf("write: %d %v", n, err)
				}
			}
			if err := w.Flush(); err != nil {
				t.Fatal(err)
			}
			if out.String() != want {
				t.Fatalf("split at %d,%d: got %q want %q", i, j, out.String(), want)
			}
		}
	}
}

func TestWriterByteAtATime(t *testing.T) {
	s := NewSet("abcdefgh", "efghijkl")
	in := "x abcdefghijkl y abcdefg z"
	var out bytes.Buffer
	w := NewWriter(s, &out)
	for i := range len(in) {
		w.Write([]byte{in[i]})
	}
	w.Flush()
	if want := "x " + Mask + " y abcdefg z"; out.String() != want {
		t.Fatalf("got %q want %q", out.String(), want)
	}
}

func TestWriterPassesPlainOutputThroughAtOnce(t *testing.T) {
	s := NewSet("abcdefgh")
	var out bytes.Buffer
	w := NewWriter(s, &out)
	w.Write([]byte("progress 10%\n"))
	if out.String() != "progress 10%\n" {
		t.Fatalf("plain output held back: %q", out.String())
	}
	// Only a possible start of a secret is held.
	w.Write([]byte("value: abc"))
	if out.String() != "progress 10%\nvalue: " {
		t.Fatalf("got %q", out.String())
	}
	w.Write([]byte("xyz\n"))
	if out.String() != "progress 10%\nvalue: abcxyz\n" {
		t.Fatalf("got %q", out.String())
	}
}

func TestWriterLearnsSecretsAddedLater(t *testing.T) {
	s := NewSet()
	var out bytes.Buffer
	w := NewWriter(s, &out)
	w.Write([]byte("before\n"))
	s.Add("late-secret")
	w.Write([]byte("now late-secret\n"))
	w.Flush()
	if out.String() != "before\nnow "+Mask+"\n" {
		t.Fatalf("got %q", out.String())
	}
}

func TestWriterIsSafeForConcurrentWrites(t *testing.T) {
	s := NewSet("abcdefgh")
	var mu sync.Mutex
	var out bytes.Buffer
	w := NewWriter(s, writerFunc(func(p []byte) (int, error) { mu.Lock(); defer mu.Unlock(); return out.Write(p) }))
	var wg sync.WaitGroup
	for range 8 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for range 100 {
				w.Write([]byte("abcdefgh\n"))
			}
		}()
	}
	wg.Wait()
	w.Flush()
	if strings.Contains(out.String(), "abcdefgh") {
		t.Fatal("secret leaked")
	}
}

type writerFunc func([]byte) (int, error)

func (f writerFunc) Write(p []byte) (int, error) { return f(p) }

func TestValueMasksNestedStrings(t *testing.T) {
	s := NewSet("hunter2-secret")
	type res struct {
		Changed bool              `json:"changed"`
		Out     string            `json:"out"`
		Tags    []string          `json:"tags"`
		M       map[string]string `json:"m"`
	}
	got := s.Value(res{true, "x hunter2-secret", []string{"hunter2-secret"}, map[string]string{"k": "hunter2-secret"}}).(map[string]any)
	if got["changed"] != true || got["out"] != "x "+Mask || got["tags"].([]any)[0] != Mask || got["m"].(map[string]any)["k"] != Mask {
		t.Fatalf("got %#v", got)
	}
	if v := NewSet().Value(res{Out: "x"}); v.(res).Out != "x" {
		t.Fatal("an empty set must return the value unchanged")
	}
}

func TestContextSet(t *testing.T) {
	ctx := context.Background()
	Add(ctx, "no-set-is-fine")
	s := NewSet()
	ctx = WithSet(ctx, s)
	Add(ctx, "from-context")
	if FromContext(ctx).String("from-context") != Mask {
		t.Fatal("value added through the context is not masked")
	}
}

func TestParseDotenv(t *testing.T) {
	env := ParseDotenv("# comment\nAPP_KEY=base64:abc=\nQUOTED=\"a \\\"b\\\" \\$HOME\\nline2 \\\\\"\nexport SINGLE='x y'\nEMPTY=\n\nBROKEN\n")
	want := map[string]string{"APP_KEY": "base64:abc=", "QUOTED": "a \"b\" $HOME\nline2 \\", "SINGLE": "x y", "EMPTY": ""}
	if len(env) != len(want) {
		t.Fatalf("got %#v", env)
	}
	for k, v := range want {
		if env[k] != v {
			t.Errorf("%s = %q, want %q", k, env[k], v)
		}
	}
	if got := FromDotenv("A=1234567\nB=7654321\n", []string{"B", "MISSING"}); len(got) != 1 || got[0] != "7654321" {
		t.Fatalf("FromDotenv = %v", got)
	}
}
