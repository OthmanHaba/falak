package fngateway

import (
	"net/url"
	"regexp"
	"strconv"
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
	escape        = regexp.MustCompile(`%[0-9A-Fa-f]{2}`)
	absolute      = regexp.MustCompile(`^([a-zA-Z][a-zA-Z0-9+.-]*)://([^?#]*)`)
	// URLs inside free text (exception messages: `Post "https://…?token=…": dial tcp …`).
	urlInText = regexp.MustCompile(`[a-zA-Z][a-zA-Z0-9+.-]*://[^\s"'<>` + "`" + `]+`)
)

func classify(seg string) string {
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

// unescape decodes percent-escapes (a few rounds, for double encoding); malformed escapes are left as they are.
func unescape(seg string) string {
	for i := 0; i < 3 && strings.Contains(seg, "%"); i++ {
		next := escape.ReplaceAllStringFunc(seg, func(e string) string {
			b, _ := strconv.ParseUint(e[1:], 16, 8)
			return string(rune(b))
		})
		if next == seg {
			break
		}
		seg = next
	}
	return seg
}

// RedactSegment returns one path segment, or {redacted} when it looks like a secret. Escaped segments are judged
// decoded (bot123%3Aabc is bot123:abc), and kept as written when they are harmless.
func RedactSegment(seg string) string {
	plain := unescape(seg)
	if plain == seg {
		return classify(seg)
	}
	if verdict := classify(plain); verdict != plain {
		return verdict
	}
	for _, part := range strings.Split(plain, "/") {
		if classify(part) != part {
			return redacted
		}
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
// redacted), safeRawURL of one that doesn't parse, or RedactPath of anything else.
func SafeURL(raw string) string {
	u, err := url.Parse(raw)
	if err != nil || u.Scheme == "" || u.Host == "" {
		return safeRawURL(raw)
	}
	// EscapedPath encodes the braces of a placeholder an earlier pass (the runtime) wrote: keep it readable.
	p := strings.ReplaceAll(u.EscapedPath(), "%7Bredacted%7D", redacted)
	if p == "" {
		p = "/"
	}
	return u.Scheme + "://" + u.Host + RedactPath(p)
}

// safeRawURL makes a URL no parser accepts (a bad port, an empty host) safe all the same: everything up to the last
// "@" before the query is userinfo and dropped (a password may hold "@" or "/"), query and fragment go, the path is
// redacted. Anything not shaped scheme://… is a path.
func safeRawURL(raw string) string {
	m := absolute.FindStringSubmatch(raw)
	if m == nil {
		return RedactPath(raw)
	}
	rest := m[2]
	if at := strings.LastIndex(rest, "@"); at >= 0 {
		rest = rest[at+1:]
	}
	host, path, ok := strings.Cut(rest, "/")
	if !ok {
		return m[1] + "://" + host + "/"
	}
	return m[1] + "://" + host + RedactPath("/"+path)
}

// redactText rewrites the URLs a free-form string quotes.
func redactText(s string) string {
	if !strings.Contains(s, "://") {
		return s
	}
	return urlInText.ReplaceAllStringFunc(s, SafeURL)
}

// urlAttrs hold a URL, a path or a route (stable and older semantic conventions; a route is a path when a framework
// records the raw path for want of a template); queryAttrs hold only a query string.
var (
	urlAttrs = map[string]bool{
		"url.full": true, "url.path": true, "url.original": true, "url.template": true,
		"http.url": true, "http.target": true, "http.route": true,
	}
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
