package fngateway

import (
	"encoding/json"
	"os"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/otlp"
)

// The same cases as the runtimes' tests (runtimes/functions/tests/redact-cases.json).
func TestRedactionMatchesTheRuntimes(t *testing.T) {
	b, err := os.ReadFile("../../../runtimes/functions/tests/redact-cases.json")
	if err != nil {
		t.Fatal(err)
	}
	var cases struct{ Paths, URLs, Texts [][2]string }
	if err := json.Unmarshal(b, &cases); err != nil || len(cases.Paths) == 0 || len(cases.URLs) == 0 {
		t.Fatalf("cases: %v", err)
	}
	for _, c := range cases.Paths {
		if got := RedactPath(c[0]); got != c[1] {
			t.Errorf("RedactPath(%q) = %q, want %q", c[0], got, c[1])
		}
	}
	for _, c := range cases.Texts {
		if got := redactText(c[0]); got != c[1] {
			t.Errorf("redactText(%q) = %q, want %q", c[0], got, c[1])
		}
	}
	for _, c := range cases.URLs {
		if got := SafeURL(c[0]); got != c[1] {
			t.Errorf("SafeURL(%q) = %q, want %q", c[0], got, c[1])
		}
	}
}

// Whatever a function sends (an old runtime, its own SDK), relayed spans carry no URL secrets.
func TestRelayedSpansAreScrubbed(t *testing.T) {
	tok := "bot123456:AAEhBP0av28X5mJBEdcJfZc3K8rT1pq0xYz"
	body := `{"resourceSpans":[{"resource":{},"scopeSpans":[{"spans":[` +
		`{"traceId":"0af7651916cd43dd8448eb211c80319c","spanId":"b7ad6b7169203331","name":"POST /` + tok + `/sendMessage","kind":3,` +
		`"attributes":[{"key":"url.full","value":{"stringValue":"https://u:p4ss@api.telegram.org/` + tok + `/sendMessage?chat=1&token=s3cret"}},` +
		`{"key":"http.target","value":{"stringValue":"/hook?api_key=k3y"}},` +
		`{"key":"http.route","value":{"stringValue":"/` + tok + `/sendMessage"}},` +
		`{"key":"url.query","value":{"stringValue":"api_key=k3y"}},` +
		`{"key":"server.address","value":{"stringValue":"api.telegram.org"}}],` +
		`"events":[{"name":"exception","attributes":[{"key":"exception.message","value":{"stringValue":"Post \"https://api.telegram.org/` + tok + `/sendMessage?x=s3cret\": dial tcp: timeout"}}]}],` +
		`"status":{"code":2,"message":"GET https://api.example.com/v1?key=s3cret failed"}}]}]}]}`

	out, err := restamp(otlp.Traces, []byte(body), true, nil)
	if err != nil {
		t.Fatal(err)
	}
	rs, err := otlp.DecodeTraces(out)
	if err != nil {
		t.Fatal(err)
	}
	sp := rs[0].ScopeSpans[0].Spans[0]
	attrs := map[string]string{}
	for _, kv := range sp.Attributes {
		attrs[kv.Key] = kv.Value.GetStringValue()
	}
	all := sp.String()
	for _, secret := range []string{"AAEhBP0av28X5mJBEdcJfZc3K8rT1pq0xYz", "s3cret", "k3y", "p4ss"} {
		if strings.Contains(all, secret) {
			t.Errorf("%q survived: %s", secret, all)
		}
	}
	if sp.Name != "POST /bot{redacted}/sendMessage" || attrs["url.full"] != "https://api.telegram.org/bot{redacted}/sendMessage" ||
		attrs["http.target"] != "/hook" || attrs["http.route"] != "/bot{redacted}/sendMessage" || attrs["server.address"] != "api.telegram.org" {
		t.Fatalf("name %q attrs %v", sp.Name, attrs)
	}
	if _, ok := attrs["url.query"]; ok {
		t.Fatal("url.query kept")
	}
	if msg := sp.Events[0].Attributes[0].Value.GetStringValue(); msg != `Post "https://api.telegram.org/bot{redacted}/sendMessage": dial tcp: timeout` {
		t.Fatalf("exception message %q", msg)
	}
	if sp.Status.Message != "GET https://api.example.com/v1 failed" {
		t.Fatalf("status %q", sp.Status.Message)
	}
}

// The gateway re-applies redaction to what a runtime already redacted: the placeholder must survive unescaped.
func TestSafeURLIsIdempotent(t *testing.T) {
	once := SafeURL("https://user:pw@api.telegram.org/bot123456:FAKEtokenFAKEtokenFAKEtoken12/getMe?token=s3cret")
	if want := "https://api.telegram.org/bot{redacted}/getMe"; once != want {
		t.Fatalf("SafeURL = %q, want %q", once, want)
	}
	if twice := SafeURL(once); twice != once {
		t.Fatalf("second pass changed %q to %q", once, twice)
	}
}
