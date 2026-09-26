// Package docker talks to the Docker Engine API over its unix socket with plain net/http (no SDK) and
// implements the docker.* commands plus deploy.container.swap (blue/green behind Caddy).
package docker

import (
	"bufio"
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"time"
)

// DefaultAPIVersion is the highest API version this client speaks (Docker Engine 24+).
const DefaultAPIVersion = "1.43"

// Client is a minimal Engine API client.
type Client struct {
	hc *http.Client

	once    sync.Once
	version string // negotiated API version
}

// NewClient returns a client for the unix socket at path.
func NewClient(socket string) *Client {
	if socket == "" {
		socket = "/var/run/docker.sock"
	}
	tr := &http.Transport{
		DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
			var d net.Dialer
			return d.DialContext(ctx, "unix", socket)
		},
		MaxIdleConns:    4,
		IdleConnTimeout: 30 * time.Second,
	}
	return &Client{hc: &http.Client{Transport: tr}}
}

// APIError is a non-2xx Engine response.
type APIError struct {
	Status  int
	Message string
}

func (e *APIError) Error() string { return fmt.Sprintf("docker api %d: %s", e.Status, e.Message) }

// IsNotFound reports a 404.
func IsNotFound(err error) bool {
	var ae *APIError
	return errors.As(err, &ae) && ae.Status == http.StatusNotFound
}

// VersionInfo is GET /version.
type VersionInfo struct {
	Version    string `json:"Version"`
	APIVersion string `json:"ApiVersion"`
	MinAPI     string `json:"MinAPIVersion"`
}

// Version returns the engine version (used for host facts).
func (c *Client) Version(ctx context.Context) (VersionInfo, error) {
	var v VersionInfo
	resp, err := c.raw(ctx, http.MethodGet, "/version", nil, nil, nil)
	if err != nil {
		return v, err
	}
	defer resp.Body.Close()
	return v, json.NewDecoder(resp.Body).Decode(&v)
}

// negotiate picks min(server ApiVersion, DefaultAPIVersion).
func (c *Client) apiVersion(ctx context.Context) string {
	c.once.Do(func() {
		c.version = DefaultAPIVersion
		if v, err := c.Version(ctx); err == nil && v.APIVersion != "" && versionLess(v.APIVersion, DefaultAPIVersion) {
			c.version = v.APIVersion
		}
	})
	return c.version
}

func versionLess(a, b string) bool {
	pa, pb := strings.Split(a, "."), strings.Split(b, ".")
	for i := 0; i < len(pa) && i < len(pb); i++ {
		x, _ := strconv.Atoi(pa[i])
		y, _ := strconv.Atoi(pb[i])
		if x != y {
			return x < y
		}
	}
	return len(pa) < len(pb)
}

func (c *Client) raw(ctx context.Context, method, path string, q url.Values, body any, hdr http.Header) (*http.Response, error) {
	u := "http://docker" + path
	if len(q) > 0 {
		u += "?" + q.Encode()
	}
	var rd io.Reader
	if body != nil {
		b, err := json.Marshal(body)
		if err != nil {
			return nil, err
		}
		rd = bytes.NewReader(b)
	}
	req, err := http.NewRequestWithContext(ctx, method, u, rd)
	if err != nil {
		return nil, err
	}
	for k, v := range hdr {
		req.Header[k] = v
	}
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	resp, err := c.hc.Do(req)
	if err != nil {
		return nil, err
	}
	if resp.StatusCode >= 400 {
		defer resp.Body.Close()
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 64<<10))
		var m struct {
			Message string `json:"message"`
		}
		if json.Unmarshal(b, &m) != nil || m.Message == "" {
			m.Message = strings.TrimSpace(string(b))
		}
		return nil, &APIError{Status: resp.StatusCode, Message: m.Message}
	}
	return resp, nil
}

// do calls a versioned endpoint and decodes JSON into out (if non-nil).
func (c *Client) do(ctx context.Context, method, path string, q url.Values, body, out any) (int, error) {
	resp, err := c.raw(ctx, method, "/v"+c.apiVersion(ctx)+path, q, body, nil)
	if err != nil {
		return 0, err
	}
	defer resp.Body.Close()
	if out != nil && resp.StatusCode != http.StatusNoContent && resp.StatusCode != http.StatusNotModified {
		if err := json.NewDecoder(resp.Body).Decode(out); err != nil && !errors.Is(err, io.EOF) {
			return resp.StatusCode, err
		}
	} else {
		_, _ = io.Copy(io.Discard, resp.Body)
	}
	return resp.StatusCode, nil
}

// Auth is registry credentials.
type Auth struct {
	Username string `json:"username"`
	Password string `json:"password"`
	Server   string `json:"server,omitempty"`
}

func (a *Auth) header() string {
	if a == nil {
		return ""
	}
	b, _ := json.Marshal(map[string]string{"username": a.Username, "password": a.Password, "serveraddress": a.Server})
	return base64.URLEncoding.EncodeToString(b)
}

// splitRef splits an image reference into fromImage and tag for POST /images/create.
func splitRef(ref string) (string, string) {
	if strings.Contains(ref, "@") {
		return ref, ""
	}
	slash := strings.LastIndex(ref, "/")
	if i := strings.LastIndex(ref, ":"); i > slash {
		return ref[:i], ref[i+1:]
	}
	return ref, "latest"
}

// ImagePull pulls ref, writing human-readable progress lines to w (may be nil).
func (c *Client) ImagePull(ctx context.Context, ref string, auth *Auth, w io.Writer) error {
	img, tag := splitRef(ref)
	q := url.Values{"fromImage": {img}}
	if tag != "" {
		q.Set("tag", tag)
	}
	hdr := http.Header{}
	if h := auth.header(); h != "" {
		hdr.Set("X-Registry-Auth", h)
	}
	resp, err := c.raw(ctx, http.MethodPost, "/v"+c.apiVersion(ctx)+"/images/create", q, nil, hdr)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	sc := bufio.NewScanner(resp.Body)
	sc.Buffer(make([]byte, 64<<10), 1<<20)
	for sc.Scan() {
		var m struct {
			Status   string `json:"status"`
			ID       string `json:"id"`
			Progress string `json:"progress"`
			Error    string `json:"error"`
		}
		if json.Unmarshal(sc.Bytes(), &m) != nil {
			continue
		}
		if m.Error != "" {
			return fmt.Errorf("pull %s: %s", ref, m.Error)
		}
		if w != nil && m.Status != "" && m.Progress == "" { // skip noisy progress-bar frames
			line := m.Status
			if m.ID != "" {
				line = m.ID + ": " + line
			}
			fmt.Fprintln(w, line)
		}
	}
	return sc.Err()
}

// ImageInspect returns the image id; exists=false on 404.
func (c *Client) ImageInspect(ctx context.Context, ref string) (id string, exists bool, err error) {
	var out struct {
		ID string `json:"Id"`
	}
	_, err = c.do(ctx, http.MethodGet, "/images/"+ref+"/json", nil, nil, &out)
	if IsNotFound(err) {
		return "", false, nil
	}
	return out.ID, err == nil, err
}

// Container is the subset of GET /containers/{id}/json we use.
type Container struct {
	ID      string `json:"Id"`
	Name    string `json:"Name"`
	Image   string `json:"Image"`
	Created string `json:"Created"`
	State   struct {
		Status  string `json:"Status"`
		Running bool   `json:"Running"`
		Health  *struct {
			Status string `json:"Status"`
		} `json:"Health,omitempty"`
	} `json:"State"`
	Config struct {
		Image  string            `json:"Image"`
		Labels map[string]string `json:"Labels"`
	} `json:"Config"`
}

// ContainerInspect returns the container; exists=false on 404.
func (c *Client) ContainerInspect(ctx context.Context, nameOrID string) (*Container, bool, error) {
	var ct Container
	_, err := c.do(ctx, http.MethodGet, "/containers/"+nameOrID+"/json", nil, nil, &ct)
	if IsNotFound(err) {
		return nil, false, nil
	}
	if err != nil {
		return nil, false, err
	}
	return &ct, true, nil
}

// ContainerSummary is an entry of GET /containers/json.
type ContainerSummary struct {
	ID      string            `json:"Id"`
	Names   []string          `json:"Names"`
	Image   string            `json:"Image"`
	State   string            `json:"State"`
	Created int64             `json:"Created"`
	Labels  map[string]string `json:"Labels"`
}

// ContainerList lists containers (all states when all=true) matching label filters ("k=v").
func (c *Client) ContainerList(ctx context.Context, all bool, labels []string) ([]ContainerSummary, error) {
	q := url.Values{}
	if all {
		q.Set("all", "1")
	}
	if len(labels) > 0 {
		f, _ := json.Marshal(map[string][]string{"label": labels})
		q.Set("filters", string(f))
	}
	var out []ContainerSummary
	_, err := c.do(ctx, http.MethodGet, "/containers/json", q, nil, &out)
	return out, err
}

// CreateBody is POST /containers/create.
type CreateBody struct {
	Image        string              `json:"Image"`
	Env          []string            `json:"Env,omitempty"`
	Cmd          []string            `json:"Cmd,omitempty"`
	Entrypoint   []string            `json:"Entrypoint,omitempty"`
	User         string              `json:"User,omitempty"`
	Labels       map[string]string   `json:"Labels,omitempty"`
	ExposedPorts map[string]struct{} `json:"ExposedPorts,omitempty"`
	HostConfig   HostConfig          `json:"HostConfig"`
}

// HostConfig subset.
type HostConfig struct {
	PortBindings  map[string][]PortBinding `json:"PortBindings,omitempty"`
	Binds         []string                 `json:"Binds,omitempty"`
	NetworkMode   string                   `json:"NetworkMode,omitempty"`
	RestartPolicy RestartPolicy            `json:"RestartPolicy"`
	Memory        int64                    `json:"Memory,omitempty"`
	NanoCPUs      int64                    `json:"NanoCpus,omitempty"`
}

// PortBinding is host ip/port.
type PortBinding struct {
	HostIP   string `json:"HostIp,omitempty"`
	HostPort string `json:"HostPort,omitempty"`
}

// RestartPolicy of a container.
type RestartPolicy struct {
	Name string `json:"Name,omitempty"`
}

// ContainerCreate creates a named container.
func (c *Client) ContainerCreate(ctx context.Context, name string, body CreateBody) (string, error) {
	var out struct {
		ID string `json:"Id"`
	}
	_, err := c.do(ctx, http.MethodPost, "/containers/create", url.Values{"name": {name}}, body, &out)
	return out.ID, err
}

// ContainerStart starts a container (already running is not an error).
func (c *Client) ContainerStart(ctx context.Context, id string) error {
	_, err := c.do(ctx, http.MethodPost, "/containers/"+id+"/start", nil, nil, nil)
	return err
}

// ContainerStop stops a container; returns stopped=false when it was not running.
func (c *Client) ContainerStop(ctx context.Context, id string, timeout time.Duration) (bool, error) {
	code, err := c.do(ctx, http.MethodPost, "/containers/"+id+"/stop", url.Values{"t": {strconv.Itoa(int(timeout.Seconds()))}}, nil, nil)
	if err != nil {
		return false, err
	}
	return code != http.StatusNotModified, nil
}

// ContainerRemove force-removes a container (404 is not an error).
func (c *Client) ContainerRemove(ctx context.Context, id string) error {
	_, err := c.do(ctx, http.MethodDelete, "/containers/"+id, url.Values{"force": {"1"}}, nil, nil)
	if IsNotFound(err) {
		return nil
	}
	return err
}

// Prune calls a prune endpoint (containers|images|volumes|networks|build) and returns reclaimed bytes.
func (c *Client) Prune(ctx context.Context, kind string, filters map[string][]string) (uint64, error) {
	q := url.Values{}
	if len(filters) > 0 {
		f, _ := json.Marshal(filters)
		q.Set("filters", string(f))
	}
	path := "/" + kind + "/prune"
	var out struct {
		SpaceReclaimed uint64 `json:"SpaceReclaimed"`
	}
	_, err := c.do(ctx, http.MethodPost, path, q, nil, &out)
	return out.SpaceReclaimed, err
}
