package edge

import (
	"fmt"
	"net"
	"regexp"
	"sort"
	"strings"
)

// Mount sends <PathPrefix> and <PathPrefix>/* of a site to another upstream: a function, through the server's
// gateway (Dial 127.0.0.1:7070 with X-Kiln-Function) or through its own domain over HTTPS (TLSServerName).
type Mount struct {
	PathPrefix     string            `json:"path_prefix"`
	StripPrefix    bool              `json:"strip_prefix,omitempty"`
	Dial           string            `json:"dial"`
	TLSServerName  string            `json:"tls_server_name,omitempty"`
	RequestHeaders map[string]string `json:"request_headers,omitempty"`
}

const maxMounts = 20

var (
	mountPrefixRe = regexp.MustCompile(`^/[A-Za-z0-9._~!$&'()*+,;=:@%/-]*$`)
	headerNameRe  = regexp.MustCompile(`^[A-Za-z0-9!#$%&'*+.^_` + "`" + `|~-]+$`)
	serverNameRe  = regexp.MustCompile(`^[A-Za-z0-9.-]{1,253}$`)
)

func (m Mount) validate() error {
	p := m.PathPrefix
	if p == "/" || len(p) > 200 || !mountPrefixRe.MatchString(p) || strings.HasSuffix(p, "/") || strings.Contains(p, "..") || strings.Contains(p, "//") {
		return fmt.Errorf("mount: invalid path_prefix %q (a path like /api, without a trailing slash)", p)
	}
	host, port, err := net.SplitHostPort(m.Dial)
	if err != nil || host == "" || port == "" {
		return fmt.Errorf("mount %s: dial must be host:port", p)
	}
	if m.TLSServerName != "" && !serverNameRe.MatchString(m.TLSServerName) {
		return fmt.Errorf("mount %s: invalid tls_server_name", p)
	}
	for k, v := range m.RequestHeaders {
		if !headerNameRe.MatchString(k) || strings.ContainsAny(v, "\r\n") {
			return fmt.Errorf("mount %s: invalid request header %q", p, k)
		}
	}
	return nil
}

// mountRoutes renders the mounts, longest prefix first (so /api/v2 wins over /api).
func mountRoutes(mounts []Mount) ([]any, error) {
	if len(mounts) > maxMounts {
		return nil, fmt.Errorf("at most %d mounts per site", maxMounts)
	}
	seen := map[string]bool{}
	sorted := append([]Mount(nil), mounts...)
	for _, m := range sorted {
		if err := m.validate(); err != nil {
			return nil, err
		}
		if seen[m.PathPrefix] {
			return nil, fmt.Errorf("mount %s: duplicate path_prefix", m.PathPrefix)
		}
		seen[m.PathPrefix] = true
	}
	sort.SliceStable(sorted, func(i, j int) bool { return len(sorted[i].PathPrefix) > len(sorted[j].PathPrefix) })

	out := make([]any, 0, len(sorted))
	for _, m := range sorted {
		proxy := obj{"handler": "reverse_proxy", "upstreams": []any{obj{"dial": m.Dial}}}
		if len(m.RequestHeaders) > 0 {
			set := obj{}
			for k, v := range m.RequestHeaders {
				set[k] = []any{v}
			}
			proxy["headers"] = obj{"request": obj{"set": set}}
		}
		if m.TLSServerName != "" {
			proxy["transport"] = obj{"protocol": "http", "tls": obj{"server_name": m.TLSServerName}}
		}
		var handle []any
		if m.StripPrefix {
			handle = append(handle, obj{"handler": "rewrite", "strip_path_prefix": m.PathPrefix})
		}
		handle = append(handle, proxy)
		out = append(out, obj{
			"match":  []any{obj{"path": []any{m.PathPrefix, m.PathPrefix + "/*"}}},
			"handle": handle,
		})
	}
	return out, nil
}
