package logs

import (
	"encoding/json"
	"fmt"
	"math"
	"net/url"
	"strconv"
	"time"

	"github.com/kiln/agent/internal/obs"
)

// caddyAccess is the part of Caddy's JSON access log entry ("handled request") that Kiln keeps. Request and
// response headers other than the user agent are dropped (they may carry cookies or tokens).
type caddyAccess struct {
	TS      float64 `json:"ts"`
	Msg     string  `json:"msg"`
	Request struct {
		RemoteIP string              `json:"remote_ip"`
		ClientIP string              `json:"client_ip"`
		Proto    string              `json:"proto"`
		Method   string              `json:"method"`
		Host     string              `json:"host"`
		URI      string              `json:"uri"`
		Headers  map[string][]string `json:"headers"`
	} `json:"request"`
	BytesRead int64   `json:"bytes_read"`
	Duration  float64 `json:"duration"` // seconds
	Size      int64   `json:"size"`
	Status    int     `json:"status"`
}

// ParseAccessLine converts one Caddy access log line into a record: body "GET /path 200 12.3ms", severity by
// status class (5xx ERROR, 4xx WARN), and OpenTelemetry HTTP semantic attributes (Loki structured metadata
// http_request_method, url_path, http_response_status_code, …). Lines that are not access entries fall back
// to ParseLine.
func ParseAccessLine(line []byte) obs.LogRecord {
	var e caddyAccess
	if err := json.Unmarshal(line, &e); err != nil || e.Request.Method == "" {
		return ParseLine(line, "json")
	}
	rec := obs.LogRecord{Time: time.Now(), Severity: "INFO"}
	if e.TS > 0 {
		sec, frac := math.Modf(e.TS)
		rec.Time = time.Unix(int64(sec), int64(frac*1e9))
	}
	switch {
	case e.Status >= 500:
		rec.Severity = "ERROR"
	case e.Status >= 400:
		rec.Severity = "WARN"
	}
	path, query := e.Request.URI, ""
	if u, err := url.ParseRequestURI(e.Request.URI); err == nil {
		path, query = u.Path, u.RawQuery
	}
	ms := e.Duration * 1000
	durMS := strconv.FormatFloat(ms, 'f', 3, 64)
	rec.Body = fmt.Sprintf("%s %s %d %sms", e.Request.Method, e.Request.URI, e.Status, strconv.FormatFloat(ms, 'f', 1, 64))
	client := e.Request.ClientIP
	if client == "" {
		client = e.Request.RemoteIP
	}
	rec.Attrs = map[string]string{
		"http.request.method":       e.Request.Method,
		"http.response.status_code": strconv.Itoa(e.Status),
		"http.response.body.size":   strconv.FormatInt(e.Size, 10),
		"http.request.body.size":    strconv.FormatInt(e.BytesRead, 10),
		"http.server.duration_ms":   durMS,
		"url.path":                  path,
		"server.address":            e.Request.Host,
		"client.address":            client,
	}
	if query != "" {
		rec.Attrs["url.query"] = query
	}
	if e.Request.Proto != "" {
		rec.Attrs["network.protocol.name"] = e.Request.Proto
	}
	if ua := e.Request.Headers["User-Agent"]; len(ua) > 0 {
		rec.Attrs["user_agent.original"] = ua[0]
	}
	return rec
}
