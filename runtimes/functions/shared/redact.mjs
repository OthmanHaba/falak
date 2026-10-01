// Secrets in URL paths never reach telemetry. The same rules are in the Python runtime (kiln_fn/redact.py), the Go
// runtime (go/runtime/kiln_runtime.go) and the function gateway (agent/internal/fngateway/redact.go), which applies
// them again to every span it relays. Query strings and userinfo are never recorded at all.

export const REDACTED = "{redacted}";

const TELEGRAM = /^bot\d+:[A-Za-z0-9_-]+$/; // api.telegram.org/bot<id>:<token>/sendMessage
const COLON_TOKEN = /^[A-Za-z0-9_-]+:[A-Za-z0-9_-]{16,}$/; // <id>:<secret>
const LONG = /^[A-Za-z0-9_:-]{32,}$/; // with a digit: API keys, webhook tokens, hashes, UUIDs (not long slugs)
const MIXED = /^[A-Za-z0-9_-]{20,}$/; // shorter tokens: upper, lower and digits mixed (Slack webhook secrets)

function classify(seg) {
  if (TELEGRAM.test(seg)) return `bot${REDACTED}`;
  if (COLON_TOKEN.test(seg) || (LONG.test(seg) && /\d/.test(seg))) return REDACTED;
  if (MIXED.test(seg) && /[a-z]/.test(seg) && /[A-Z]/.test(seg) && /\d/.test(seg)) return REDACTED;
  return seg;
}

/** Percent-escapes decoded (a few rounds, for double encoding); malformed escapes are left as they are. */
function unescape(seg) {
  for (let i = 0; i < 3 && seg.includes("%"); i++) {
    const next = seg.replace(/%([0-9A-Fa-f]{2})/g, (_, h) => String.fromCharCode(parseInt(h, 16)));
    if (next === seg) break;
    seg = next;
  }
  return seg;
}

/**
 * One path segment, or REDACTED when it looks like a secret. Escaped segments are judged decoded (bot123%3Aabc is
 * bot123:abc), and kept as written when they are harmless.
 */
export function redactSegment(seg) {
  const plain = unescape(seg);
  if (plain === seg) return classify(seg);
  const verdict = classify(plain);
  if (verdict !== plain) return verdict;
  return plain.split("/").some((part) => classify(part) !== part) ? REDACTED : seg;
}

/** A URL path with secret-looking segments replaced: /bot123:AAE…/sendMessage → /bot{redacted}/sendMessage. */
export function redactPath(path) {
  return path.split("/").map(redactSegment).join("/");
}

const ABSOLUTE = /^([a-zA-Z][a-zA-Z0-9+.-]*):\/\/([^?#]*)/;

/**
 * A URL no parser accepts (a bad port, an empty host), made safe all the same: everything up to the last "@" before
 * the query is userinfo and dropped (a password may hold "@" or "/"), query and fragment go, the path is redacted.
 */
export function safeRawUrl(raw) {
  const m = ABSOLUTE.exec(raw);
  if (!m) return redactPath(raw.split(/[?#]/)[0]);
  let rest = m[2];
  const at = rest.lastIndexOf("@");
  if (at >= 0) rest = rest.slice(at + 1);
  const slash = rest.indexOf("/");
  const host = slash < 0 ? rest : rest.slice(0, slash);
  return `${m[1]}://${host}${slash < 0 ? "/" : redactPath(rest.slice(slash))}`;
}

/** scheme://host[:port]/path of a URL: no userinfo, no query, no fragment, secret segments redacted. */
export function safeUrl(url) {
  return `${url.protocol}//${url.host}${redactPath(url.pathname)}`;
}

const URL_IN_TEXT = /[a-zA-Z][a-zA-Z0-9+.-]*:\/\/[^\s"'<>`]+/g;

/** Free text (an error message) with every URL it quotes made safe. */
export function redactText(text) {
  if (!text.includes("://")) return text;
  return text.replace(URL_IN_TEXT, (raw) => {
    let url;
    try {
      url = new URL(raw);
    } catch {
      return safeRawUrl(raw);
    }
    return url.host ? safeUrl(url) : safeRawUrl(raw);
  });
}
