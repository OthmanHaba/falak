// Package edge renders the Kiln edge model into Caddy JSON and applies it atomically through the
// Caddy (or FrankenPHP) admin API.
package edge

import (
	"fmt"
	"path"
	"sort"
)

// Payload is edge.caddy.apply.
type Payload struct {
	ACMEEmail string         `json:"acme_email,omitempty"`
	ACMECA    string         `json:"acme_ca,omitempty"`
	Sites     []Site         `json:"sites"`
	Raw       map[string]any `json:"raw,omitempty"`
}

// Site is one routed site.
type Site struct {
	ID              string            `json:"id"`
	Domains         []string          `json:"domains"`
	RedirectDomains []string          `json:"redirect_domains,omitempty"`
	TLS             *TLS              `json:"tls,omitempty"`
	Kind            string            `json:"kind"`
	Root            string            `json:"root,omitempty"`
	PHPFPMSocket    string            `json:"php_fpm_socket,omitempty"`
	Upstreams       []Upstream        `json:"upstreams,omitempty"`
	LBPolicy        string            `json:"lb_policy,omitempty"`
	HealthURI       string            `json:"health_uri,omitempty"`
	RedirectTo      string            `json:"redirect_to,omitempty"`
	Headers         map[string]string `json:"headers,omitempty"`
	BasicAuth       []BasicAuth       `json:"basic_auth,omitempty"`
	Redirects       []Redirect        `json:"redirects,omitempty"`
	DenyIPs         []string          `json:"deny_ips,omitempty"`
	AllowIPs        []string          `json:"allow_ips,omitempty"`
	MaxBodyBytes    int64             `json:"max_body_bytes,omitempty"`
	Encode          *bool             `json:"encode,omitempty"`
}

// TLS settings.
type TLS struct {
	Mode     string `json:"mode,omitempty"` // acme (default) | internal | custom | off
	CertName string `json:"cert_name,omitempty"`
	DNS      *DNS   `json:"dns,omitempty"` // ACME DNS-01 challenge (wildcards)
}

// DNS configures the ACME DNS-01 challenge provider.
type DNS struct {
	Provider string `json:"provider"` // cloudflare
	APIToken string `json:"api_token"`
}

// Upstream is a reverse-proxy target.
type Upstream struct {
	Dial string `json:"dial"`
}

// BasicAuth account.
type BasicAuth struct {
	Username     string `json:"username"`
	PasswordHash string `json:"password_hash"`
	Path         string `json:"path,omitempty"` // Caddy path matcher; empty = whole site
}

// Redirect rule.
type Redirect struct {
	From   string `json:"from"`
	To     string `json:"to"`
	Status int    `json:"status,omitempty"`
}

// AdminListen is where the admin API listens (never exposed publicly).
const AdminListen = "localhost:2019"

// RouteID / UpstreamsID are stable @id values addressable through /id/<id>.
func RouteID(site string) string     { return "kiln-site-" + site }
func UpstreamsID(site string) string { return "kiln-upstreams-" + site }

type obj = map[string]any

func (s Site) tlsMode() string {
	if s.TLS == nil || s.TLS.Mode == "" {
		return "acme"
	}
	return s.TLS.Mode
}

// Render converts the payload into a complete Caddy JSON config. certDir is where edge.cert.install
// stores custom certificates.
func Render(p Payload, certDir string) (obj, error) {
	if p.Raw != nil {
		raw := cloneMap(p.Raw)
		// The admin endpoint must stay local no matter what the raw config says.
		raw["admin"] = obj{"listen": AdminListen}
		return raw, nil
	}
	seen := map[string]string{}
	var tlsRoutes, plainRoutes []any
	var acmeSubjects, internalSubjects, skipCerts []string
	var dnsPolicies []any
	var loadFiles []any
	sites := append([]Site(nil), p.Sites...)
	sort.SliceStable(sites, func(i, j int) bool { return sites[i].ID < sites[j].ID })
	for _, s := range sites {
		if s.ID == "" || len(s.Domains) == 0 {
			return nil, fmt.Errorf("site %q: id and domains are required", s.ID)
		}
		for _, d := range append(append([]string{}, s.Domains...), s.RedirectDomains...) {
			if other, dup := seen[d]; dup {
				return nil, fmt.Errorf("domain %s used by sites %s and %s", d, other, s.ID)
			}
			seen[d] = s.ID
		}
		routes, err := siteRoutes(s)
		if err != nil {
			return nil, fmt.Errorf("site %s: %w", s.ID, err)
		}
		hosts := append(append([]string{}, s.Domains...), s.RedirectDomains...)
		switch s.tlsMode() {
		case "off":
			plainRoutes = append(plainRoutes, routes...)
		case "internal":
			internalSubjects = append(internalSubjects, hosts...)
			tlsRoutes = append(tlsRoutes, routes...)
		case "custom":
			if s.TLS.CertName == "" {
				return nil, fmt.Errorf("site %s: tls.cert_name required for custom TLS", s.ID)
			}
			skipCerts = append(skipCerts, hosts...)
			loadFiles = append(loadFiles, obj{
				"certificate": path.Join(certDir, s.TLS.CertName+".crt"),
				"key":         path.Join(certDir, s.TLS.CertName+".key"),
				"tags":        []any{"kiln-" + s.TLS.CertName},
			})
			tlsRoutes = append(tlsRoutes, routes...)
		case "acme":
			if s.TLS != nil && s.TLS.DNS != nil {
				if s.TLS.DNS.Provider == "" || s.TLS.DNS.APIToken == "" {
					return nil, fmt.Errorf("site %s: tls.dns provider and api_token are required", s.ID)
				}
				iss := acmeIssuer(p)
				iss["challenges"] = obj{"dns": obj{"provider": obj{"name": s.TLS.DNS.Provider, "api_token": s.TLS.DNS.APIToken}}}
				dnsPolicies = append(dnsPolicies, obj{"subjects": toAny(hosts), "issuers": []any{iss}})
			} else {
				acmeSubjects = append(acmeSubjects, hosts...)
			}
			tlsRoutes = append(tlsRoutes, routes...)
		default:
			return nil, fmt.Errorf("site %s: unknown tls mode %q", s.ID, s.tlsMode())
		}
	}
	servers := obj{}
	if len(tlsRoutes) > 0 {
		srv := obj{"listen": []any{":443"}, "routes": tlsRoutes}
		if len(skipCerts) > 0 {
			srv["automatic_https"] = obj{"skip_certificates": toAny(skipCerts)}
		}
		servers["kiln"] = srv
	}
	if len(plainRoutes) > 0 {
		// Caddy adds its HTTP→HTTPS redirects for TLS sites to this existing :80 server.
		servers["kiln_http"] = obj{"listen": []any{":80"}, "routes": plainRoutes}
	}
	cfg := obj{
		"admin": obj{"listen": AdminListen},
		"apps":  obj{"http": obj{"servers": servers}},
	}
	tlsApp := obj{}
	var policies []any
	if len(internalSubjects) > 0 {
		policies = append(policies, obj{"subjects": toAny(internalSubjects), "issuers": []any{obj{"module": "internal"}}})
	}
	policies = append(policies, dnsPolicies...)
	if len(acmeSubjects) > 0 && (p.ACMEEmail != "" || p.ACMECA != "") {
		policies = append(policies, obj{"subjects": toAny(acmeSubjects), "issuers": []any{acmeIssuer(p)}})
	}
	if len(policies) > 0 {
		tlsApp["automation"] = obj{"policies": policies}
	}
	if len(loadFiles) > 0 {
		tlsApp["certificates"] = obj{"load_files": loadFiles}
	}
	if len(tlsApp) > 0 {
		cfg["apps"].(obj)["tls"] = tlsApp
	}
	return cfg, nil
}

func siteRoutes(s Site) ([]any, error) {
	var out []any
	if len(s.RedirectDomains) > 0 {
		scheme := "https"
		if s.tlsMode() == "off" {
			scheme = "http"
		}
		out = append(out, obj{
			"match": []any{obj{"host": toAny(s.RedirectDomains)}},
			"handle": []any{obj{"handler": "static_response", "status_code": 308,
				"headers": obj{"Location": []any{scheme + "://" + s.Domains[0] + "{http.request.uri}"}}}},
			"terminal": true,
		})
	}
	var sub []any
	if len(s.DenyIPs) > 0 {
		sub = append(sub, obj{"match": []any{obj{"remote_ip": obj{"ranges": toAny(s.DenyIPs)}}}, "handle": []any{forbidden()}})
	}
	if len(s.AllowIPs) > 0 {
		sub = append(sub, obj{"match": []any{obj{"not": []any{obj{"remote_ip": obj{"ranges": toAny(s.AllowIPs)}}}}}, "handle": []any{forbidden()}})
	}
	if len(s.BasicAuth) > 0 {
		// Group accounts by path; site-wide accounts ("") come first, then paths in first-seen order.
		byPath := map[string][]any{}
		var paths []string
		for _, a := range s.BasicAuth {
			if _, ok := byPath[a.Path]; !ok {
				paths = append(paths, a.Path)
			}
			byPath[a.Path] = append(byPath[a.Path], obj{"username": a.Username, "password": a.PasswordHash})
		}
		sort.SliceStable(paths, func(i, j int) bool { return paths[i] == "" && paths[j] != "" })
		for _, p := range paths {
			route := obj{"handle": []any{obj{"handler": "authentication", "providers": obj{"http_basic": obj{
				"accounts": byPath[p], "hash": obj{"algorithm": "bcrypt"}}}}}}
			if p != "" {
				route["match"] = []any{obj{"path": []any{p}}}
			}
			sub = append(sub, route)
		}
	}
	if len(s.Headers) > 0 {
		set := obj{}
		for k, v := range s.Headers {
			set[k] = []any{v}
		}
		sub = append(sub, obj{"handle": []any{obj{"handler": "headers", "response": obj{"set": set}}}})
	}
	if s.MaxBodyBytes > 0 {
		sub = append(sub, obj{"handle": []any{obj{"handler": "request_body", "max_size": s.MaxBodyBytes}}})
	}
	for _, r := range s.Redirects {
		st := r.Status
		if st == 0 {
			st = 301
		}
		sub = append(sub, obj{"match": []any{obj{"path": []any{r.From}}}, "handle": []any{obj{
			"handler": "static_response", "status_code": st, "headers": obj{"Location": []any{r.To}}}}})
	}
	if s.Encode == nil || *s.Encode {
		sub = append(sub, obj{"handle": []any{obj{"handler": "encode",
			"encodings": obj{"zstd": obj{}, "gzip": obj{}}, "prefer": []any{"zstd", "gzip"}}}})
	}
	switch s.Kind {
	case "static":
		if s.Root == "" {
			return nil, fmt.Errorf("root required for static")
		}
		sub = append(sub, obj{"handle": []any{obj{"handler": "vars", "root": s.Root}, obj{"handler": "file_server"}}})
	case "php_fpm", "frankenphp":
		if s.Root == "" {
			return nil, fmt.Errorf("root required for %s", s.Kind)
		}
		if s.Kind == "php_fpm" && s.PHPFPMSocket == "" {
			return nil, fmt.Errorf("php_fpm_socket required")
		}
		sub = append(sub, phpRoutes(s)...)
	case "reverse_proxy":
		if len(s.Upstreams) == 0 {
			return nil, fmt.Errorf("upstreams required for reverse_proxy")
		}
		sub = append(sub, obj{"handle": []any{reverseProxy(s)}})
	case "redirect":
		if s.RedirectTo == "" {
			return nil, fmt.Errorf("redirect_to required")
		}
		sub = append(sub, obj{"handle": []any{obj{"handler": "static_response", "status_code": 308, "headers": obj{"Location": []any{s.RedirectTo}}}}})
	default:
		return nil, fmt.Errorf("unknown kind %q", s.Kind)
	}
	out = append(out, obj{
		"@id":      RouteID(s.ID),
		"match":    []any{obj{"host": toAny(s.Domains)}},
		"handle":   []any{obj{"handler": "subroute", "routes": sub}},
		"terminal": true,
	})
	return out, nil
}

// phpRoutes mirrors Caddy's php_fastcgi / FrankenPHP php_server expansion. With resolve_root_symlink the
// fastcgi transport resolves `current` per request, but FrankenPHP resolves it when the handler is
// provisioned: deploy.activate therefore force-reloads the config (Client.ReloadFrankenPHP), and
// Manager.ensureRoots gives never-deployed sites a placeholder release so the config always loads.
func phpRoutes(s Site) []any {
	tryFiles := obj{"file": obj{"try_files": []any{"{http.request.uri.path}", "{http.request.uri.path}/index.php", "index.php"}, "split_path": []any{".php"}, "root": s.Root}}
	var phpHandler obj
	if s.Kind == "frankenphp" {
		phpHandler = obj{"handler": "php", "root": s.Root, "split_path": []any{".php"}, "resolve_root_symlink": true}
	} else {
		phpHandler = obj{"handler": "reverse_proxy", "transport": obj{"protocol": "fastcgi", "root": s.Root, "split_path": []any{".php"}, "resolve_root_symlink": true},
			"upstreams": []any{obj{"dial": "unix/" + s.PHPFPMSocket}}}
	}
	return []any{
		obj{"handle": []any{obj{"handler": "vars", "root": s.Root}}},
		obj{"match": []any{obj{"file": obj{"try_files": []any{"{http.request.uri.path}/index.php"}, "root": s.Root}, "not": []any{obj{"path": []any{"*/"}}}}},
			"handle": []any{obj{"handler": "static_response", "status_code": 308, "headers": obj{"Location": []any{"{http.request.orig_uri.path}/{http.request.orig_uri.prefixed_query}"}}}}},
		obj{"match": []any{tryFiles}, "handle": []any{obj{"handler": "rewrite", "uri": "{http.matchers.file.relative}"}}},
		obj{"match": []any{obj{"path": []any{"*.php"}}}, "handle": []any{phpHandler}},
		obj{"handle": []any{obj{"handler": "file_server", "hide": []any{".env", ".git"}}}},
	}
}

func reverseProxy(s Site) obj {
	var ups []any
	for _, u := range s.Upstreams {
		ups = append(ups, obj{"dial": u.Dial})
	}
	h := obj{"@id": UpstreamsID(s.ID), "handler": "reverse_proxy", "upstreams": ups}
	policy := s.LBPolicy
	if policy == "" {
		policy = "round_robin"
	}
	h["load_balancing"] = obj{"selection_policy": obj{"policy": policy}, "try_duration": "5s"}
	if s.HealthURI != "" {
		h["health_checks"] = obj{"active": obj{"uri": s.HealthURI, "interval": "10s", "timeout": "5s"}}
	}
	return h
}

func acmeIssuer(p Payload) obj {
	iss := obj{"module": "acme"}
	if p.ACMEEmail != "" {
		iss["email"] = p.ACMEEmail
	}
	if p.ACMECA != "" {
		iss["ca"] = p.ACMECA
	}
	return iss
}

func forbidden() obj { return obj{"handler": "static_response", "status_code": 403} }

func toAny(ss []string) []any {
	out := make([]any, len(ss))
	for i, s := range ss {
		out[i] = s
	}
	return out
}

func cloneMap(m map[string]any) map[string]any {
	out := make(map[string]any, len(m))
	for k, v := range m {
		out[k] = v
	}
	return out
}
