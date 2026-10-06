package redact

import (
	"bytes"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"strings"
	"testing"
)

func TestShiftedBase64InsideALongerEncoding(t *testing.T) {
	s := NewSet("hunter2-Secret")
	// Basic auth: the password sits after "user:" at every alignment.
	for _, user := range []string{"a", "ab", "abc", "abcd"} {
		header := "Authorization: Basic " + base64.StdEncoding.EncodeToString([]byte(user+":hunter2-Secret"))
		got := s.String(header)
		if !strings.Contains(got, Mask) {
			t.Errorf("user %q: shifted base64 not masked: %q", user, got)
		}
		// Whatever is left must not decode back to the secret.
		if strings.Contains(got, base64.StdEncoding.EncodeToString([]byte("hunter2-Secret"))[:8]) {
			t.Errorf("user %q: %q", user, got)
		}
	}
}

func TestJSONHexAndShellForms(t *testing.T) {
	secret := `p"a/ss<w>&'ö🔑`
	s := NewSet(secret)
	goJSON, _ := json.Marshal(map[string]string{"pw": secret})
	var plain bytes.Buffer
	enc := json.NewEncoder(&plain)
	enc.SetEscapeHTML(false)
	enc.Encode(map[string]string{"pw": secret})
	bs := string(rune(92))
	php := `{"pw":"p\"a\/ss<w>&'` + bs + "u00f6" + bs + "ud83d" + bs + "udd11" + `"}`
	for name, text := range map[string]string{
		"go json":   string(goJSON),
		"plain":     plain.String(),
		"php json":  php,
		"hex":       "dump " + hex.EncodeToString([]byte(secret)),
		"shell":     `export PW='` + strings.ReplaceAll(secret, "'", `'\''`) + `'`,
		"raw quote": "x " + secret,
	} {
		if got := s.String(text); !strings.Contains(got, Mask) || strings.Contains(got, "ss<w>") || strings.Contains(got, `ss<w`) {
			t.Errorf("%s: %q -> %q", name, text, got)
		}
	}
}

func TestNewSetMinMasksShortTokens(t *testing.T) {
	if got := NewSetMin(4, "abcd").String("token abcd!"); got != "token ••••!" {
		t.Fatalf("got %q", got)
	}
	// Derived forms of a short value are too unspecific to match.
	if got := NewSetMin(4, "abcd").String(hex.EncodeToString([]byte("zz"))); strings.Contains(got, Mask) {
		t.Fatalf("got %q", got)
	}
}

func TestWriterHoldsAtMostMaxHold(t *testing.T) {
	long := strings.Repeat("k", MaxHold+100)
	s := NewSet(long)
	var out bytes.Buffer
	w := NewWriter(s, &out)
	// The start of the long secret keeps arriving: the writer must not hold everything.
	w.Write([]byte("start " + long[:MaxHold+50]))
	if out.Len() == 0 {
		t.Fatal("nothing released past MaxHold")
	}
	if len(w.carry) > MaxHold {
		t.Fatalf("carry %d > MaxHold", len(w.carry))
	}
	// Short secrets are still masked whole around the forced cut.
	s2 := NewSet(long, "short-secret")
	out.Reset()
	w = NewWriter(s2, &out)
	w.Write([]byte(strings.Repeat("x", 10) + "short-secret" + long[:MaxHold+10]))
	w.Flush()
	if strings.Contains(out.String(), "short-secret") {
		t.Fatal("short secret leaked at the forced cut")
	}
}
