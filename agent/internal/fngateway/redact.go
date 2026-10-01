package fngateway

import (
	"net/url"
	"regexp"
	"strings"

	commonpb "go.opentelemetry.io/proto/otlp/common/v1"
	tracepb "go.opentelemetry.io/proto/otlp/trace/v1"
)

// Secrets in URLs never leave the server through function telemetry. The runtimes already record URLs without
// query strings or userinfo and redact secret-looking path segments (runtimes/functions/shared/redact.mjs and its
// Python and Go copies, same rules, same test cases); the gateway applies the rules again to every span it relays,
// so an older runtime image, a function's own OpenTelemetry SDK or an error message quoting a URL can't leak one.

const redacted = "{redacted}"

var (
	telegramSeg   = regexp.MustCompile(`^bot\d+:[A-Za-z0-9_-]+$`)
	colonTokenSeg = regexp.MustCompile(`^[A-Za-z0-9_-]+:[A-Za-z0-9_-]{16,}$`)
	longSeg       = regexp.MustCompile(`^[A-Za-z0-9_:-]{32,}$`)
	mixedSeg      = regexp.MustCompile(`^[A-Za-z0-9_-]{20,}$`)
	// URLs inside free text (exception messages: `Post "https://…?token=…": dial tcp …`).
	urlInText = regexp.MustCompile(`[a-zA-Z][a-zA-Z0-9+.-]*://[^\s"'<>` + "`" + `]+`)
)

// RedactSegment returns one path segment, or {redacted} when it looks like a secret.
func RedactSegment(seg string) string {
	digit := strings.ContainsAny(seg, "0123456789")
	switch {
	case telegramSeg.MatchString(seg):
		return "bot" + redacted
	case colonTokenSeg.MatchString(seg), longSeg.MatchString(seg) && digit:
		return redacted
	case mixedSeg.MatchString(seg) && digit && strings.ToLower(seg) != seg && strings.ToUpper(seg) != seg:
		return redacted
	}
	return seg
}

// RedactPath: /bot123:AAE…/sendMessage → /bot{redacted}/sendMessage. A query string, if any, is dropped.
func RedactPath(p string) string {
	p, _, _ = strings.Cut(p, "?")
	p, _, _ = strings.Cut(p, "#")
	segs := strings.Split(p, "/")
	for i, s := range segs {
		segs[i] = RedactSegment(s)
	}
	return strings.Join(segs, "/")
}

// SafeURL is scheme://host[:port]/path of an absolute URL (no userinfo, query or fragment; secret segments
// redacted), or RedactPath of anything else.
func SafeURL(raw string) string {
	u, err := url.Parse(raw)
	if err != nil || u.Scheme == "" || u.Host == "" {
		return RedactPath(raw)
	}
	// EscapedPath encodes the braces of a placeholder an earlier pass (the runtime) wrote: keep it readable.
	p := strings.ReplaceAll(u.EscapedPath(), "%7Bredacted%7D", redacted)
	if p == "" {
		p = "/"
	}
	return u.Scheme + "://" + u.Host + RedactPath(p)
}

// redactText rewrites the URLs a free-form string quotes.
func redactText(s string) string {
	if !strings.Contains(s, "://") {
		return s
	}
	return urlInText.ReplaceAllStringFunc(s, SafeURL)
}

// urlAttrs hold a URL or a path (stable and older semantic conventions); queryAttrs hold only a query string.
var (
	urlAttrs   = map[string]bool{"url.full": true, "url.path": true, "url.original": true, "http.url": true, "http.target": true}
	queryAttrs = map[string]bool{"url.query": true, "http.query": true}
	textAttrs  = map[string]bool{"exception.message": true, "exception.stacktrace": true}
)

// redactSpans scrubs every span of a batch: URL attributes, URL-looking span names, exception texts and status
// messages.
func redactSpans(rs []*tracepb.ResourceSpans) {
	for _, r := range rs {
		for _, ss := range r.GetScopeSpans() {
			for _, sp := range ss.GetSpans() {
				sp.Name = redactName(sp.GetName())
				sp.Attributes = redactAttrs(sp.GetAttributes())
				for _, ev := range sp.GetEvents() {
					ev.Attributes = redactAttrs(ev.GetAttributes())
				}
				if st := sp.GetStatus(); st != nil {
					st.Message = redactText(st.GetMessage())
				}
			}
		}
	}
}

// redactName: "GET /bot123:AAE…/x" → "GET /bot{redacted}/x" (a path after the method, or a whole URL).
func redactName(name string) string {
	method, rest, ok := strings.Cut(name, " ")
	if ok && strings.HasPrefix(rest, "/") {
		return method + " " + RedactPath(rest)
	}
	return redactText(name)
}

func redactAttrs(attrs []*commonpb.KeyValue) []*commonpb.KeyValue {
	kept := attrs[:0]
	for _, kv := range attrs {
		k := kv.GetKey()
		if queryAttrs[k] {
			continue
		}
		if sv, ok := kv.GetValue().GetValue().(*commonpb.AnyValue_StringValue); ok {
			switch {
			case urlAttrs[k]:
				sv.StringValue = SafeURL(sv.StringValue)
			case textAttrs[k]:
				sv.StringValue = redactText(sv.StringValue)
			}
		}
		kept = append(kept, kv)
	}
	return kept
}
