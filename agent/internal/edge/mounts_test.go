package edge

import (
	"encoding/json"
	"strings"
	"testing"
)

func mountSite(m ...Mount) Site {
	return Site{ID: "shop", Domains: []string{"shop.test"}, Kind: "reverse_proxy", Upstreams: []Upstream{{Dial: "127.0.0.1:3000"}},
		DenyIPs: []string{"192.0.2.0/24"}, BasicAuth: []BasicAuth{{Username: "ops", PasswordHash: "$2y$10$abc"}},
		Redirects: []Redirect{{From: "/old", To: "/new"}}, Mounts: m}
}

func renderJSON(t *testing.T, s Site) string {
	t.Helper()
	cfg, err := Render(Payload{Sites: []Site{s}}, "/etc/kiln/certs")
	if err != nil {
		t.Fatal(err)
	}
	b, _ := json.Marshal(cfg)
	return string(b)
}

func TestRenderMounts(t *testing.T) {
	out := renderJSON(t, mountSite(
		Mount{PathPrefix: "/api", StripPrefix: true, Dial: "127.0.0.1:7070",
			RequestHeaders: map[string]string{"X-Kiln-Function": "api", "X-Kiln-Client-IP": "{http.vars.client_ip}"}},
		Mount{PathPrefix: "/api/v2", Dial: "fn-v2.example.com:443", TLSServerName: "fn-v2.example.com",
			RequestHeaders: map[string]string{"Host": "fn-v2.example.com", "X-Kiln-Client-IP": "{http.vars.client_ip}"}},
	))

	for _, want := range []string{
		`{"handle":[{"handler":"reverse_proxy","headers":{"request":{"set":{"Host":["fn-v2.example.com"],"X-Kiln-Client-IP":["{http.vars.client_ip}"]}}},"transport":{"protocol":"http","tls":{"server_name":"fn-v2.example.com"}},"upstreams":[{"dial":"fn-v2.example.com:443"}]}],"match":[{"path":["/api/v2","/api/v2/*"]}]}`,
		`{"handle":[{"handler":"rewrite","strip_path_prefix":"/api"},{"handler":"reverse_proxy","headers":{"request":{"set":{"X-Kiln-Client-IP":["{http.vars.client_ip}"],"X-Kiln-Function":["api"]}}},"upstreams":[{"dial":"127.0.0.1:7070"}]}],"match":[{"path":["/api","/api/*"]}]}`,
	} {
		if !strings.Contains(out, want) {
			t.Fatalf("missing %s\nin %s", want, out)
		}
	}

	// Order: access rules, then mounts (longest prefix first), then the site's redirects and handler.
	order := []string{`"client_ip":{"ranges":["192.0.2.0/24"]}`, `"http_basic"`, `"/api/v2/*"`, `"/api/*"`, `"Location":["/new"]`, `"127.0.0.1:3000"`}
	last := -1
	for _, s := range order {
		i := strings.Index(out, s)
		if i < 0 || i < last {
			t.Fatalf("%s out of order (at %d, previous %d):\n%s", s, i, last, out)
		}
		last = i
	}
}

func TestRenderMountValidation(t *testing.T) {
	ok := Mount{PathPrefix: "/api", Dial: "127.0.0.1:7070"}
	for name, m := range map[string]Mount{
		"root":            {PathPrefix: "/", Dial: ok.Dial},
		"trailing slash":  {PathPrefix: "/api/", Dial: ok.Dial},
		"relative":        {PathPrefix: "api", Dial: ok.Dial},
		"dot dot":         {PathPrefix: "/a/../b", Dial: ok.Dial},
		"double slash":    {PathPrefix: "//evil", Dial: ok.Dial},
		"space":           {PathPrefix: "/a b", Dial: ok.Dial},
		"no port":         {PathPrefix: "/api", Dial: "127.0.0.1"},
		"bad header":      {PathPrefix: "/api", Dial: ok.Dial, RequestHeaders: map[string]string{"Bad Header": "x"}},
		"header newline":  {PathPrefix: "/api", Dial: ok.Dial, RequestHeaders: map[string]string{"X-A": "a\r\nX-B: b"}},
		"bad server name": {PathPrefix: "/api", Dial: "a:443", TLSServerName: "a b"},
	} {
		if _, err := Render(Payload{Sites: []Site{mountSite(m)}}, "/etc/kiln/certs"); err == nil {
			t.Errorf("%s: accepted %+v", name, m)
		}
	}
	if _, err := Render(Payload{Sites: []Site{mountSite(ok, ok)}}, "/etc/kiln/certs"); err == nil {
		t.Error("duplicate prefixes accepted")
	}
	many := make([]Mount, 21)
	for i := range many {
		many[i] = Mount{PathPrefix: "/m" + strings.Repeat("x", i), Dial: ok.Dial}
	}
	if _, err := Render(Payload{Sites: []Site{mountSite(many...)}}, "/etc/kiln/certs"); err == nil {
		t.Error("21 mounts accepted")
	}
}
