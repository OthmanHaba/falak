// Package transport implements the agent side of the control-plane protocol: mTLS HTTP client,
// command long-poll, batched NDJSON event posting, heartbeats and insights.
package transport

import (
	"bytes"
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"math/rand/v2"
	"net"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/kiln/agent/internal/commands"
)

// Client talks to the mTLS API base URL (e.g. https://agents.kiln.example/agent/v1).
type Client struct {
	base string
	hc   *http.Client
	// UserAgent is sent on every request.
	UserAgent string
}

// New creates a client; tlsConf should come from enroll.Identity.TLSConfig().
func New(apiBase string, tlsConf *tls.Config) *Client {
	tr := &http.Transport{
		Proxy:               http.ProxyFromEnvironment,
		DialContext:         (&net.Dialer{Timeout: 15 * time.Second, KeepAlive: 30 * time.Second}).DialContext,
		TLSClientConfig:     tlsConf,
		TLSHandshakeTimeout: 15 * time.Second,
		MaxIdleConnsPerHost: 4,
		IdleConnTimeout:     90 * time.Second,
		ForceAttemptHTTP2:   true,
	}
	return NewWithHTTPClient(apiBase, &http.Client{Transport: tr})
}

// NewWithHTTPClient uses a caller-provided http.Client (tests).
func NewWithHTTPClient(apiBase string, hc *http.Client) *Client {
	return &Client{base: strings.TrimRight(apiBase, "/"), hc: hc, UserAgent: "kiln-agent"}
}

// StatusError is a non-2xx response.
type StatusError struct {
	Code int
	Body string
}

func (e *StatusError) Error() string { return fmt.Sprintf("HTTP %d: %s", e.Code, e.Body) }

// Retryable reports whether a failed request should be retried later.
func Retryable(err error) bool {
	var se *StatusError
	if errors.As(err, &se) {
		return se.Code == 401 || se.Code == 408 || se.Code == 425 || se.Code == 429 || se.Code >= 500
	}
	return true // network errors
}

func (c *Client) do(ctx context.Context, method, path, ctype string, body []byte, timeout time.Duration) ([]byte, error) {
	if timeout > 0 {
		var cancel context.CancelFunc
		ctx, cancel = context.WithTimeout(ctx, timeout)
		defer cancel()
	}
	var rd io.Reader
	if body != nil {
		rd = bytes.NewReader(body)
	}
	req, err := http.NewRequestWithContext(ctx, method, c.base+path, rd)
	if err != nil {
		return nil, err
	}
	if ctype != "" {
		req.Header.Set("Content-Type", ctype)
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", c.UserAgent)
	resp, err := c.hc.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	out, err := io.ReadAll(io.LimitReader(resp.Body, 16<<20))
	if err != nil {
		return nil, err
	}
	if resp.StatusCode/100 != 2 {
		b := strings.TrimSpace(string(out))
		if len(b) > 512 {
			b = b[:512]
		}
		return nil, &StatusError{Code: resp.StatusCode, Body: b}
	}
	return out, nil
}

// Poll long-polls GET /commands?wait=N.
func (c *Client) Poll(ctx context.Context, wait int) ([]commands.Envelope, error) {
	body, err := c.do(ctx, http.MethodGet, "/commands?wait="+strconv.Itoa(wait), "", nil, time.Duration(wait+30)*time.Second)
	if err != nil {
		return nil, err
	}
	if len(bytes.TrimSpace(body)) == 0 {
		return nil, nil
	}
	var r struct {
		Commands []commands.Envelope `json:"commands"`
	}
	if err := json.Unmarshal(body, &r); err != nil {
		return nil, fmt.Errorf("decode commands: %w", err)
	}
	return r.Commands, nil
}

// PostEvents posts NDJSON events for one command.
func (c *Client) PostEvents(ctx context.Context, commandID string, evs []commands.Event) error {
	var buf bytes.Buffer
	enc := json.NewEncoder(&buf) // Encode appends '\n' → NDJSON
	for _, e := range evs {
		if err := enc.Encode(e); err != nil {
			return err
		}
	}
	_, err := c.do(ctx, http.MethodPost, "/commands/"+commandID+"/events", "application/x-ndjson", buf.Bytes(), 30*time.Second)
	return err
}

// Heartbeat posts a heartbeat document.
func (c *Client) Heartbeat(ctx context.Context, hb any) error {
	b, err := json.Marshal(hb)
	if err != nil {
		return err
	}
	_, err = c.do(ctx, http.MethodPost, "/heartbeat", "application/json", b, 20*time.Second)
	return err
}

// PostInsights posts NDJSON insight lines (implements the insights/cron sink interfaces).
func (c *Client) PostInsights(ctx context.Context, ndjson []byte) error {
	_, err := c.do(ctx, http.MethodPost, "/insights", "application/x-ndjson", ndjson, 30*time.Second)
	return err
}

// Renew posts a CSR to /renew and returns the new certificate PEM.
func (c *Client) Renew(ctx context.Context, csrPEM []byte) ([]byte, error) {
	b, _ := json.Marshal(map[string]string{"csr_pem": string(csrPEM)})
	out, err := c.do(ctx, http.MethodPost, "/renew", "application/json", b, 60*time.Second)
	if err != nil {
		return nil, err
	}
	var r struct {
		CertPEM string `json:"cert_pem"`
	}
	if err := json.Unmarshal(out, &r); err != nil || r.CertPEM == "" {
		return nil, fmt.Errorf("renew: bad response: %v", err)
	}
	return []byte(r.CertPEM), nil
}

// Backoff is exponential with full jitter.
type Backoff struct {
	Min, Max time.Duration
	n        int
}

// Next returns the next delay.
func (b *Backoff) Next() time.Duration {
	min, max := b.Min, b.Max
	if min <= 0 {
		min = 500 * time.Millisecond
	}
	if max <= 0 {
		max = 30 * time.Second
	}
	d := min << min64(b.n, 20)
	if d > max || d <= 0 {
		d = max
	}
	b.n++
	return d/2 + time.Duration(rand.Int64N(int64(d/2)+1))
}

// Reset after success.
func (b *Backoff) Reset() { b.n = 0 }

func min64(a, b int) int {
	if a < b {
		return a
	}
	return b
}

func sleep(ctx context.Context, d time.Duration) bool {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return false
	case <-t.C:
		return true
	}
}
