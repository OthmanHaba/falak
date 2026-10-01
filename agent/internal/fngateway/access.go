package fngateway

import (
	"crypto/sha256"
	"crypto/subtle"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"net/http"
	"net/netip"
	"regexp"
	"slices"
	"strings"
)

// Access control of a function, enforced before a request wakes the function: API keys (stored as sha256
// hashes) and an IP allowlist. Scheduled runs are not affected.

const (
	// KeyHeader carries a function API key (alternative to Authorization: Bearer).
	KeyHeader = "X-Kiln-Key"
	// ClientIPHeader is the client IP Caddy resolved ({http.vars.client_ip}, Cloudflare-aware).
	ClientIPHeader = "X-Kiln-Client-IP"

	maxAccessEntries = 50
)

// Access is a function's access policy; empty lists mean no restriction.
type Access struct {
	APIKeyHashes []string `json:"api_key_hashes,omitempty"`
	AllowCIDRs   []string `json:"allow_cidrs,omitempty"`
}

var keyHashRe = regexp.MustCompile(`^[0-9a-f]{64}$`)

// Normalize validates the policy and canonicalises it (lowercase hashes, masked CIDRs; a bare IP is a /32 or /128).
func (a *Access) Normalize() error {
	if len(a.APIKeyHashes) > maxAccessEntries || len(a.AllowCIDRs) > maxAccessEntries {
		return fmt.Errorf("access: at most %d keys and %d CIDRs", maxAccessEntries, maxAccessEntries)
	}
	for i, h := range a.APIKeyHashes {
		h = strings.ToLower(strings.TrimSpace(h))
		if !keyHashRe.MatchString(h) {
			return errors.New("access: api_key_hashes must be sha256 hex digests")
		}
		a.APIKeyHashes[i] = h
	}
	for i, c := range a.AllowCIDRs {
		p, err := netip.ParsePrefix(strings.TrimSpace(c))
		if err != nil {
			addr, aerr := netip.ParseAddr(strings.TrimSpace(c))
			if aerr != nil {
				return fmt.Errorf("access: invalid CIDR %q", c)
			}
			p = netip.PrefixFrom(addr, addr.BitLen())
		}
		a.AllowCIDRs[i] = p.Masked().String()
	}
	return nil
}

func (a Access) equal(b Access) bool {
	return slices.Equal(a.APIKeyHashes, b.APIKeyHashes) && slices.Equal(a.AllowCIDRs, b.AllowCIDRs)
}

// check returns 0 when the request passes, else the status and reason. On success it also returns strip, which
// removes the key header it used; call it only once admission is final (the rules may change meanwhile).
func (a Access) check(r *http.Request) (status int, reason string, strip func()) {
	strip = func() {}
	if len(a.AllowCIDRs) > 0 {
		ip, ok := clientIP(r)
		if !ok || !slices.ContainsFunc(a.AllowCIDRs, func(c string) bool {
			p, err := netip.ParsePrefix(c)
			return err == nil && p.Contains(ip)
		}) {
			return http.StatusForbidden, "your IP address is not allowed", strip
		}
	}
	if len(a.APIKeyHashes) > 0 {
		key, fromAuth := r.Header.Get(KeyHeader), false
		if key == "" {
			if v := r.Header.Get("Authorization"); len(v) > 7 && strings.EqualFold(v[:7], "bearer ") {
				key, fromAuth = strings.TrimSpace(v[7:]), true
			}
		}
		if key == "" {
			return http.StatusUnauthorized, "an API key is required", strip
		}
		sum := sha256.Sum256([]byte(key))
		got := hex.EncodeToString(sum[:])
		match := 0
		for _, h := range a.APIKeyHashes {
			match |= subtle.ConstantTimeCompare([]byte(got), []byte(h))
		}
		if match != 1 {
			return http.StatusUnauthorized, "invalid API key", strip
		}
		strip = func() {
			r.Header.Del(KeyHeader)
			if fromAuth {
				r.Header.Del("Authorization")
			}
		}
	}
	return 0, "", strip
}

// clientIP is X-Kiln-Client-IP (set by Caddy), else the TCP peer.
func clientIP(r *http.Request) (netip.Addr, bool) {
	if v := strings.TrimSpace(r.Header.Get(ClientIPHeader)); v != "" {
		if ip, err := netip.ParseAddr(v); err == nil {
			return ip.Unmap(), true
		}
	}
	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		host = r.RemoteAddr
	}
	ip, err := netip.ParseAddr(host)
	return ip.Unmap(), err == nil
}

func writeAccessError(w http.ResponseWriter, status int, reason string) {
	if status == http.StatusUnauthorized {
		w.Header().Set("WWW-Authenticate", `Bearer realm="function"`)
	}
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(map[string]string{"error": reason})
}
