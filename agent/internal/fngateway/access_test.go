package fngateway

import (
	"crypto/sha256"
	"encoding/hex"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func keyHash(k string) string {
	sum := sha256.Sum256([]byte(k))
	return hex.EncodeToString(sum[:])
}

func do(t *testing.T, srv *httptest.Server, site string, h map[string]string) (int, string) {
	t.Helper()
	req, _ := http.NewRequest(http.MethodGet, srv.URL+"/hello", nil)
	req.Header.Set(Header, site)
	for k, v := range h {
		req.Header.Set(k, v)
	}
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(resp.Body)
	return resp.StatusCode, string(b)
}

func TestAccessAPIKeysAndAllowlist(t *testing.T) {
	e := newFake()
	g, clk, srv := newGateway(t, e)
	s := spec("hello", "r1")
	s.Access = Access{APIKeyHashes: []string{strings.ToUpper(keyHash("k-one")), keyHash("k-two")}}
	mustApply(t, g, s)
	idleDown(g, clk, 2*time.Minute)
	_, _, startsBefore := e.count()

	// Missing or wrong key: 401, and the sleeping function is not started.
	if code, body := do(t, srv, "hello", nil); code != 401 || !strings.Contains(body, "API key is required") {
		t.Fatalf("missing key: %d %s", code, body)
	}
	if code, _ := do(t, srv, "hello", map[string]string{"Authorization": "Bearer nope"}); code != 401 {
		t.Fatalf("wrong key: %d", code)
	}
	if _, _, starts := e.count(); starts != startsBefore || e.running("r1") != 0 {
		t.Fatalf("a rejected request woke the function (%d starts)", starts-startsBefore)
	}

	// Bearer and X-Kiln-Key work; the key and the client IP header never reach the function.
	e.mu.Lock()
	e.echoHeaders = true
	e.mu.Unlock()
	if code, body := do(t, srv, "hello", map[string]string{"Authorization": "Bearer k-one", ClientIPHeader: "198.51.100.7"}); code != 200 || strings.Contains(body, "k-one") || strings.Contains(body, "198.51.100.7") {
		t.Fatalf("bearer: %d %s", code, body)
	}
	if code, body := do(t, srv, "hello", map[string]string{KeyHeader: "k-two", "Authorization": "Basic app-own"}); code != 200 || strings.Contains(body, "k-two") || !strings.Contains(body, "Basic app-own") {
		t.Fatalf("x-kiln-key: %d %s", code, body)
	}

	// Allowlist (IPv4 + IPv6) combined with the key; changing access keeps the instance.
	s.Access = Access{APIKeyHashes: []string{keyHash("k-one")}, AllowCIDRs: []string{"203.0.113.0/24", "2001:db8::1"}}
	mustApply(t, g, s)
	if e.running("r1") != 1 {
		t.Fatalf("access change rebooted instances: %d running", e.running("r1"))
	}
	for ip, want := range map[string]int{"203.0.113.9": 200, "2001:db8::1": 200, "198.51.100.7": 403, "2001:db8::2": 403} {
		if code, _ := do(t, srv, "hello", map[string]string{KeyHeader: "k-one", ClientIPHeader: ip}); code != want {
			t.Fatalf("ip %s: %d, want %d", ip, code, want)
		}
	}
	// Allowed IP but no key: 401.
	if code, _ := do(t, srv, "hello", map[string]string{ClientIPHeader: "203.0.113.9"}); code != 401 {
		t.Fatalf("allowed IP without key: %d", code)
	}
	// Without the header, the TCP peer (127.0.0.1) is checked: not in the list.
	if code, _ := do(t, srv, "hello", map[string]string{KeyHeader: "k-one"}); code != 403 {
		t.Fatalf("peer address: %d", code)
	}
	// Open again.
	s.Access = Access{}
	mustApply(t, g, s)
	if code, _ := do(t, srv, "hello", nil); code != 200 {
		t.Fatalf("open: %d", code)
	}
}

func TestAccessValidation(t *testing.T) {
	for _, a := range []Access{{APIKeyHashes: []string{"abc"}}, {AllowCIDRs: []string{"not-an-ip"}}, {AllowCIDRs: make([]string, 51)}} {
		s := spec("hello", "r1")
		s.Access = a
		if err := s.Normalize(); err == nil {
			t.Fatalf("accepted %+v", a)
		}
	}
	s := spec("hello", "r1")
	s.Access = Access{AllowCIDRs: []string{"10.1.2.3/8", "192.0.2.4"}}
	if err := s.Normalize(); err != nil || s.Access.AllowCIDRs[0] != "10.0.0.0/8" || s.Access.AllowCIDRs[1] != "192.0.2.4/32" {
		t.Fatalf("%v %v", err, s.Access.AllowCIDRs)
	}
}
