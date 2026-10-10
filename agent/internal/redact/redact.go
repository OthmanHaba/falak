// Package redact masks secret values in everything the agent ships back to the control plane: command output,
// errors and results, deployment lifecycle events, process logs and build logs.
//
// The control plane never sends extra copies of a secret for this: payloads list the *names* of their secret
// variables (`mask`), and the executor looks their values up in the same payload (env maps, dotenv content) or in
// the site's env file. Each value is matched in every form it commonly leaks in (see forms); every match becomes
// Mask. Values shorter than MinLen are ignored: masking "true" or "3306" would mangle unrelated output and hide
// nothing worth hiding.
package redact

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/url"
	"sort"
	"strings"
	"sync"
	"unicode/utf8"
)

// Mask replaces every secret.
const Mask = "••••"

// MinLen is the shortest value that is masked.
const MinLen = 6

// minForm is the shortest encoded form matched (shorter fragments of base64 would match unrelated text).
const minForm = 6

// MaxHold bounds what a Writer holds back waiting for the rest of a possible secret: past it, output is released
// (masked as far as it is known), so a secret longer than MaxHold may show its first bytes when split across writes.
const MaxHold = 8 << 10

// Set is a growing set of secret values; safe for concurrent use. A nil *Set masks nothing.
type Set struct {
	mu     sync.RWMutex
	min    int
	values map[string]struct{}
	pats   [][]byte // every form of every value, longest first
	maxLen int
}

// NewSet returns a set holding values.
func NewSet(values ...string) *Set { return NewSetMin(MinLen, values...) }

// NewSetMin returns a set that also masks values of at least min bytes (builds: registry and repository tokens).
func NewSetMin(min int, values ...string) *Set {
	s := &Set{min: max(1, min), values: map[string]struct{}{}}
	s.Add(values...)
	return s
}

// Add adds values (shorter than the set's minimum are ignored).
func (s *Set) Add(values ...string) {
	if s == nil {
		return
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	added := false
	for _, v := range values {
		if len(v) < s.min {
			continue
		}
		if _, ok := s.values[v]; ok {
			continue
		}
		s.values[v] = struct{}{}
		added = true
	}
	if !added {
		return
	}
	seen := map[string]bool{}
	s.pats, s.maxLen = nil, 0
	for v := range s.values {
		for i, f := range forms(v) {
			// The value itself always counts; derived forms only when long enough to be specific.
			if seen[f] || (i > 0 && len(f) < minForm) {
				continue
			}
			seen[f] = true
			s.pats = append(s.pats, []byte(f))
			s.maxLen = max(s.maxLen, len(f))
		}
	}
	sort.Slice(s.pats, func(i, j int) bool {
		if len(s.pats[i]) != len(s.pats[j]) {
			return len(s.pats[i]) > len(s.pats[j])
		}
		return bytes.Compare(s.pats[i], s.pats[j]) < 0
	})
}

// Empty reports whether the set masks nothing.
func (s *Set) Empty() bool {
	if s == nil {
		return true
	}
	s.mu.RLock()
	defer s.mu.RUnlock()
	return len(s.pats) == 0
}

func (s *Set) patterns() ([][]byte, int) {
	if s == nil {
		return nil, 0
	}
	s.mu.RLock()
	defer s.mu.RUnlock()
	return s.pats, s.maxLen
}

// forms are the encodings a value is matched in (the value itself first):
//   - base64, standard and URL alphabets, at each of the three byte alignments it can have inside a longer encoded
//     string (e.g. "user:password" in a Basic auth header), keeping only the characters that depend on the value
//     alone (the approach of GitHub Actions' log masker);
//   - URL-encoded (query and path escaping), lower-case hex;
//   - JSON string escapes (Go's, with and without HTML escaping, and PHP's json_encode: \/ and \uXXXX);
//   - inside a single-quoted shell word (each quote closed, escaped and reopened).
func forms(v string) []string {
	b := []byte(v)
	out := []string{v}
	for _, enc := range []*base64.Encoding{base64.StdEncoding, base64.URLEncoding} {
		for shift := 0; shift < 3; shift++ {
			full := enc.EncodeToString(append(make([]byte, shift), b...))
			from := (8*shift + 5) / 6
			to := 8 * (shift + len(b)) / 6
			if to > from {
				out = append(out, full[from:to])
			}
		}
	}
	out = append(out, url.QueryEscape(v), url.PathEscape(v), hex.EncodeToString(b))
	out = append(out, jsonForms(v)...)
	if strings.Contains(v, "'") {
		out = append(out, strings.ReplaceAll(v, "'", `'\''`))
	}
	return out
}

// jsonForms are v as it appears inside a JSON string (without the quotes).
func jsonForms(v string) []string {
	var out []string
	if b, err := json.Marshal(v); err == nil {
		out = append(out, string(b[1:len(b)-1]))
	}
	var buf bytes.Buffer
	enc := json.NewEncoder(&buf)
	enc.SetEscapeHTML(false)
	if enc.Encode(v) == nil {
		b := bytes.TrimSpace(buf.Bytes())
		out = append(out, string(b[1:len(b)-1]))
	}
	// PHP's json_encode defaults: "/" escaped, non-ASCII as \uXXXX (UTF-16 surrogate pairs), <>& kept.
	var php strings.Builder
	for _, r := range v {
		switch {
		case r == '"' || r == '\\' || r == '/':
			php.WriteByte('\\')
			php.WriteRune(r)
		case r == '\n':
			php.WriteString(`\n`)
		case r == '\r':
			php.WriteString(`\r`)
		case r == '\t':
			php.WriteString(`\t`)
		case r < 0x20:
			fmt.Fprintf(&php, `\u%04x`, r)
		case r < utf8.RuneSelf:
			php.WriteRune(r)
		case r > 0xFFFF:
			r -= 0x10000
			fmt.Fprintf(&php, `\u%04x\u%04x`, 0xD800+(r>>10), 0xDC00+(r&0x3FF))
		default:
			fmt.Fprintf(&php, `\u%04x`, r)
		}
	}
	return append(out, php.String())
}

// String masks every secret in in.
func (s *Set) String(in string) string {
	pats, _ := s.patterns()
	if len(pats) == 0 {
		return in
	}
	b := []byte(in)
	return string(apply(b, spans(b, pats), len(b)))
}

// Bytes masks every secret in b (a new slice when anything matched).
func (s *Set) Bytes(b []byte) []byte {
	pats, _ := s.patterns()
	if len(pats) == 0 {
		return b
	}
	return apply(b, spans(b, pats), len(b))
}

// Value masks every string inside a JSON-like value (command results). Values that don't marshal are returned as is.
func (s *Set) Value(v any) any {
	if v == nil || s.Empty() {
		return v
	}
	raw, err := json.Marshal(v)
	if err != nil {
		return v
	}
	var doc any
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.UseNumber()
	if err := dec.Decode(&doc); err != nil {
		return v
	}
	return s.walk(doc)
}

func (s *Set) walk(v any) any {
	switch t := v.(type) {
	case string:
		return s.String(t)
	case []any:
		for i := range t {
			t[i] = s.walk(t[i])
		}
	case map[string]any:
		for k, e := range t {
			t[k] = s.walk(e)
		}
	}
	return v
}

// spans returns the merged, sorted [start, end) ranges of b covered by any pattern. Overlapping and adjacent
// matches of different secrets merge, so no tail of one survives next to the mask of another.
func spans(b []byte, pats [][]byte) [][2]int {
	var out [][2]int
	for _, p := range pats {
		for off := 0; off < len(b); {
			i := bytes.Index(b[off:], p)
			if i < 0 {
				break
			}
			out = append(out, [2]int{off + i, off + i + len(p)})
			off += i + 1
		}
	}
	if len(out) < 2 {
		return out
	}
	sort.Slice(out, func(i, j int) bool { return out[i][0] < out[j][0] })
	merged := out[:1]
	for _, r := range out[1:] {
		last := &merged[len(merged)-1]
		if r[0] <= last[1] {
			last[1] = max(last[1], r[1])
			continue
		}
		merged = append(merged, r)
	}
	return merged
}

// apply writes b[:end] with every span (inside it) replaced by Mask.
func apply(b []byte, sp [][2]int, end int) []byte {
	if len(sp) == 0 {
		return b[:end]
	}
	out := make([]byte, 0, end)
	at := 0
	for _, r := range sp {
		if r[0] >= end {
			break
		}
		out = append(out, b[at:r[0]]...)
		out = append(out, Mask...)
		at = r[1]
	}
	if at < end {
		out = append(out, b[at:end]...)
	}
	return out
}

// holdBack is the length of the longest suffix of b that is a proper prefix of a pattern: a secret that may
// continue in the next write.
func holdBack(b []byte, pats [][]byte, maxLen int) int {
	from := max(0, len(b)-(maxLen-1))
	best := 0
	for _, p := range pats {
		start := max(from, len(b)-(len(p)-1))
		for j := start; j < len(b) && len(b)-j > best; j++ {
			if b[j] == p[0] && bytes.HasPrefix(p, b[j:]) {
				best = len(b) - j
				break
			}
		}
	}
	return best
}

// Writer masks secrets in a stream of writes. A secret split across writes is still masked: the end of a write
// that could be the start of a secret is held back (at most the longest form minus one byte) until the next write
// or Flush. Everything else passes through at once.
type Writer struct {
	set   *Set
	w     io.Writer
	mu    sync.Mutex
	carry []byte
}

// NewWriter returns a Writer masking s's secrets on their way to w.
func NewWriter(s *Set, w io.Writer) *Writer { return &Writer{set: s, w: w} }

// Write masks and forwards p (or holds its possible secret prefix back).
func (w *Writer) Write(p []byte) (int, error) {
	w.mu.Lock()
	defer w.mu.Unlock()
	pats, maxLen := w.set.patterns()
	if len(pats) == 0 && len(w.carry) == 0 {
		if _, err := w.w.Write(p); err != nil {
			return 0, err
		}
		return len(p), nil
	}
	buf := append(w.carry, p...)
	sp := spans(buf, pats)
	cut := len(buf) - holdBack(buf, pats, maxLen)
	// A match reaching past the cut is held back whole and found again with the next write.
	for _, r := range sp {
		if r[0] < cut && r[1] > cut {
			cut = r[0]
		}
	}
	// Never hold more than MaxHold: release the oldest part (matches crossing the new cut are masked whole).
	if len(buf)-cut > MaxHold {
		cut = len(buf) - MaxHold
		for _, r := range sp {
			if r[0] < cut && r[1] > cut {
				cut = r[1]
			}
		}
	}
	out := apply(buf, sp, cut)
	w.carry = append([]byte(nil), buf[cut:]...)
	if len(out) > 0 {
		if _, err := w.w.Write(out); err != nil {
			return 0, err
		}
	}
	return len(p), nil
}

// Flush masks and forwards what was held back.
func (w *Writer) Flush() error {
	w.mu.Lock()
	defer w.mu.Unlock()
	if len(w.carry) == 0 {
		return nil
	}
	pats, _ := w.set.patterns()
	out := apply(w.carry, spans(w.carry, pats), len(w.carry))
	w.carry = nil
	_, err := w.w.Write(out)
	return err
}

type ctxKey struct{}

// WithSet attaches s to ctx (the dispatcher does it for every command).
func WithSet(ctx context.Context, s *Set) context.Context { return context.WithValue(ctx, ctxKey{}, s) }

// FromContext returns the command's set (nil when none: masks nothing).
func FromContext(ctx context.Context) *Set {
	s, _ := ctx.Value(ctxKey{}).(*Set)
	return s
}

// Add adds values to the command's set.
func Add(ctx context.Context, values ...string) { FromContext(ctx).Add(values...) }

// FromEnv returns the values of keys in env.
func FromEnv(env map[string]string, keys []string) []string {
	var out []string
	for _, k := range keys {
		if v, ok := env[k]; ok {
			out = append(out, v)
		}
	}
	return out
}

// FromDotenv returns the values of keys in dotenv content.
func FromDotenv(content string, keys []string) []string {
	if len(keys) == 0 || content == "" {
		return nil
	}
	return FromEnv(ParseDotenv(content), keys)
}

// ParseDotenv reads the dotenv files the control plane writes: KEY=value, KEY="escaped" (\\ \" \n \$) and
// KEY='literal'; blank lines and # comments are skipped, an `export ` prefix is allowed.
func ParseDotenv(content string) map[string]string {
	env := map[string]string{}
	for _, line := range strings.Split(content, "\n") {
		line = strings.TrimSpace(strings.TrimSuffix(line, "\r"))
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		line = strings.TrimPrefix(line, "export ")
		k, v, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		k = strings.TrimSpace(k)
		switch {
		case len(v) >= 2 && v[0] == '"' && v[len(v)-1] == '"':
			v = unescape(v[1 : len(v)-1])
		case len(v) >= 2 && v[0] == '\'' && v[len(v)-1] == '\'':
			v = v[1 : len(v)-1]
		}
		env[k] = v
	}
	return env
}

func unescape(s string) string {
	if !strings.Contains(s, `\`) {
		return s
	}
	var b strings.Builder
	for i := 0; i < len(s); i++ {
		if s[i] != '\\' || i+1 == len(s) {
			b.WriteByte(s[i])
			continue
		}
		i++
		switch s[i] {
		case 'n':
			b.WriteByte('\n')
		default: // \\ \" \$
			b.WriteByte(s[i])
		}
	}
	return b.String()
}
