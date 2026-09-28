package facts

import (
	"context"
	"io"
	"net"
	"net/http"
	"strings"
	"sync"
	"time"
)

// Cloud VMs with a 1:1 NAT public address (AWS EC2, GCP, Azure) only carry their private address on an
// interface, so the public one is asked from the provider's instance metadata service. The answer is
// cached for the process (it only changes with a stop/start, which restarts the agent) — including "none",
// so machines outside those clouds pay the short timeouts once.

// MetadataEndpoint is the link-local instance metadata address (overridable in tests).
var MetadataEndpoint = "http://169.254.169.254"

// GCPMetadataEndpoint is GCP's metadata server (overridable in tests).
var GCPMetadataEndpoint = "http://metadata.google.internal"

var cloudIPv4 struct {
	sync.Mutex
	done bool
	ip   *string
}

// resetCloudIPv4 forgets the cached lookup (tests).
func resetCloudIPv4() {
	cloudIPv4.Lock()
	cloudIPv4.done, cloudIPv4.ip = false, nil
	cloudIPv4.Unlock()
}

// CloudPublicIPv4 returns the instance's public IPv4 from the cloud metadata service, or nil.
func CloudPublicIPv4(ctx context.Context) *string {
	cloudIPv4.Lock()
	defer cloudIPv4.Unlock()
	if cloudIPv4.done {
		return cloudIPv4.ip
	}
	client := &http.Client{Timeout: 2 * time.Second, Transport: &http.Transport{Proxy: nil}}
	for _, lookup := range []func(context.Context, *http.Client) string{awsPublicIPv4, gcpPublicIPv4, azurePublicIPv4} {
		if s := lookup(ctx, client); s != "" {
			if ip := net.ParseIP(s).To4(); ip != nil && !IsPrivate(ip) && !ip.IsLoopback() && !ip.IsLinkLocalUnicast() {
				v := ip.String()
				cloudIPv4.ip = &v
				break
			}
		}
	}
	cloudIPv4.done = ctx.Err() == nil // a cancelled lookup is retried next time
	return cloudIPv4.ip
}

func metadataGet(ctx context.Context, c *http.Client, method, url string, headers map[string]string) (string, int) {
	req, err := http.NewRequestWithContext(ctx, method, url, nil)
	if err != nil {
		return "", 0
	}
	for k, v := range headers {
		req.Header.Set(k, v)
	}
	resp, err := c.Do(req)
	if err != nil {
		return "", 0
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(io.LimitReader(resp.Body, 4096))
	return strings.TrimSpace(string(b)), resp.StatusCode
}

// awsPublicIPv4 uses IMDSv2 (session token), which EC2 requires by default on new instances.
func awsPublicIPv4(ctx context.Context, c *http.Client) string {
	token, status := metadataGet(ctx, c, http.MethodPut, MetadataEndpoint+"/latest/api/token", map[string]string{"X-aws-ec2-metadata-token-ttl-seconds": "60"})
	if status != http.StatusOK || token == "" {
		return ""
	}
	ip, status := metadataGet(ctx, c, http.MethodGet, MetadataEndpoint+"/latest/meta-data/public-ipv4", map[string]string{"X-aws-ec2-metadata-token": token})
	if status != http.StatusOK {
		return ""
	}
	return ip
}

func gcpPublicIPv4(ctx context.Context, c *http.Client) string {
	ip, status := metadataGet(ctx, c, http.MethodGet, GCPMetadataEndpoint+"/computeMetadata/v1/instance/network-interfaces/0/access-configs/0/external-ip", map[string]string{"Metadata-Flavor": "Google"})
	if status != http.StatusOK {
		return ""
	}
	return ip
}

func azurePublicIPv4(ctx context.Context, c *http.Client) string {
	ip, status := metadataGet(ctx, c, http.MethodGet, MetadataEndpoint+"/metadata/instance/network/interface/0/ipv4/ipAddress/0/publicIpAddress?api-version=2021-02-01&format=text", map[string]string{"Metadata": "true"})
	if status != http.StatusOK {
		return ""
	}
	return ip
}
