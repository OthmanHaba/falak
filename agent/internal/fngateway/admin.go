package fngateway

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"time"
)

// AdminHandler is the admin API served on the unix socket:
//
//	GET    /v1/version           {"version": "…"}
//	GET    /v1/functions         [Status]
//	PUT    /v1/functions/{site}  Spec → ApplyResult
//	DELETE /v1/functions/{site}  {"removed": bool}
func (g *Gateway) AdminHandler() http.Handler {
	mux := http.NewServeMux()
	reply := func(w http.ResponseWriter, code int, v any) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(code)
		_ = json.NewEncoder(w).Encode(v)
	}
	fail := func(w http.ResponseWriter, code int, err error) {
		reply(w, code, map[string]string{"error": err.Error()})
	}

	mux.HandleFunc("GET /v1/version", func(w http.ResponseWriter, _ *http.Request) {
		reply(w, 200, map[string]string{"version": g.o.Version})
	})
	mux.HandleFunc("GET /v1/functions", func(w http.ResponseWriter, _ *http.Request) { reply(w, 200, g.Status()) })
	mux.HandleFunc("PUT /v1/functions/{site}", func(w http.ResponseWriter, r *http.Request) {
		var spec Spec
		dec := json.NewDecoder(io.LimitReader(r.Body, 8<<20))
		dec.DisallowUnknownFields()
		if err := dec.Decode(&spec); err != nil {
			fail(w, 400, err)
			return
		}
		if spec.Site != r.PathValue("site") {
			fail(w, 400, errors.New("site in path and body differ"))
			return
		}
		if err := spec.Normalize(); err != nil {
			fail(w, 400, err)
			return
		}
		res, err := g.Apply(r.Context(), spec)
		if err != nil {
			fail(w, 422, err)
			return
		}
		reply(w, 200, res)
	})
	mux.HandleFunc("DELETE /v1/functions/{site}", func(w http.ResponseWriter, r *http.Request) {
		removed, err := g.Delete(r.Context(), r.PathValue("site"))
		if err != nil {
			fail(w, 500, err)
			return
		}
		reply(w, 200, map[string]bool{"removed": removed})
	})
	return mux
}

// Serve runs the proxy on listen and the admin API on the unix socket until ctx ends, then drains in-flight
// requests (containers keep running and are adopted by the next start).
func (g *Gateway) Serve(ctx context.Context, listen, adminSocket string) error {
	pl, err := net.Listen("tcp", listen)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(adminSocket), 0o750); err != nil {
		pl.Close()
		return err
	}
	_ = os.Remove(adminSocket)
	al, err := net.Listen("unix", adminSocket)
	if err != nil {
		pl.Close()
		return err
	}
	_ = os.Chmod(adminSocket, 0o600)

	proxy := &http.Server{Handler: g, ReadHeaderTimeout: 30 * time.Second}
	admin := &http.Server{Handler: g.AdminHandler(), ReadHeaderTimeout: 10 * time.Second}
	errc := make(chan error, 2)
	go func() { errc <- proxy.Serve(pl) }()
	go func() { errc <- admin.Serve(al) }()

	reapCtx, stopReap := context.WithCancel(ctx)
	defer stopReap()
	if g.o.ReapInterval > 0 {
		go func() {
			t := time.NewTicker(g.o.ReapInterval)
			defer t.Stop()
			for {
				select {
				case <-reapCtx.Done():
					return
				case <-t.C:
					g.Reap()
				}
			}
		}()
	}
	g.o.Logger.Info("function gateway listening", "listen", listen, "admin", adminSocket)

	select {
	case <-ctx.Done():
	case err := <-errc:
		if !errors.Is(err, http.ErrServerClosed) {
			return err
		}
	}
	sctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()
	_ = admin.Shutdown(sctx)
	_ = proxy.Shutdown(sctx)
	return nil
}

// Client talks to the admin API over the unix socket.
type Client struct {
	Socket string
	hc     *http.Client
}

// NewClient returns a client for the socket ("" = DefaultAdmin).
func NewClient(socket string) *Client {
	if socket == "" {
		socket = DefaultAdmin
	}
	tr := &http.Transport{DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
		var d net.Dialer
		return d.DialContext(ctx, "unix", socket)
	}}
	return &Client{Socket: socket, hc: &http.Client{Transport: tr}}
}

func (c *Client) call(ctx context.Context, method, path string, in, out any) error {
	var body io.Reader
	if in != nil {
		b, err := json.Marshal(in)
		if err != nil {
			return err
		}
		body = bytes.NewReader(b)
	}
	req, err := http.NewRequestWithContext(ctx, method, "http://fn-gateway"+path, body)
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	resp, err := c.hc.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(io.LimitReader(resp.Body, 4<<20))
	if resp.StatusCode >= 300 {
		var e struct {
			Error string `json:"error"`
		}
		if json.Unmarshal(b, &e) == nil && e.Error != "" {
			return errors.New(e.Error)
		}
		return fmt.Errorf("function gateway: %s", resp.Status)
	}
	if out != nil {
		return json.Unmarshal(b, out)
	}
	return nil
}

// Version of the running gateway.
func (c *Client) Version(ctx context.Context) (string, error) {
	var v struct {
		Version string `json:"version"`
	}
	err := c.call(ctx, http.MethodGet, "/v1/version", nil, &v)
	return v.Version, err
}

// Apply registers a release.
func (c *Client) Apply(ctx context.Context, spec Spec) (ApplyResult, error) {
	var res ApplyResult
	err := c.call(ctx, http.MethodPut, "/v1/functions/"+spec.Site, spec, &res)
	return res, err
}

// Delete deregisters a function.
func (c *Client) Delete(ctx context.Context, site string) (bool, error) {
	var res struct {
		Removed bool `json:"removed"`
	}
	err := c.call(ctx, http.MethodDelete, "/v1/functions/"+site, nil, &res)
	return res.Removed, err
}

// Status of every function.
func (c *Client) Status(ctx context.Context) ([]Status, error) {
	var out []Status
	err := c.call(ctx, http.MethodGet, "/v1/functions", nil, &out)
	return out, err
}
