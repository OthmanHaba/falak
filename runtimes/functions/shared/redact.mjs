// Secrets in URL paths never reach telemetry. The same rules are in the Python runtime (kiln_fn/redact.py), the Go
// runtime (go/runtime/kiln_runtime.go) and the function gateway (agent/internal/fngateway/redact.go), which applies
// them again to every span it relays. Query strings and userinfo are never recorded at all.

export const REDACTED = "{redacted}";

const TELEGRAM = /^bot\d+:[A-Za-z0-9_-]+$/; // api.telegram.org/bot<id>:<token>/sendMessage
const COLON_TOKEN = /^[A-Za-z0-9_-]+:[A-Za-z0-9_-]{16,}$/; // <id>:<secret>
const LONG = /^[A-Za-z0-9_:-]{32,}$/; // with a digit: API keys, webhook tokens, hashes, UUIDs (not long slugs)
const MIXED = /^[A-Za-z0-9_-]{20,}$/; // shorter tokens: upper, lower and digits mixed (Slack webhook secrets)

/** One path segment, or REDACTED when it looks like a secret. */
export function redactSegment(seg) {
  if (TELEGRAM.test(seg)) return `bot${REDACTED}`;
  if (COLON_TOKEN.test(seg) || (LONG.test(seg) && /\d/.test(seg))) return REDACTED;
  if (MIXED.test(seg) && /[a-z]/.test(seg) && /[A-Z]/.test(seg) && /\d/.test(seg)) return REDACTED;
  return seg;
}

/** A URL path with secret-looking segments replaced: /bot123:AAE…/sendMessage → /bot{redacted}/sendMessage. */
export function redactPath(path) {
  return path.split("/").map(redactSegment).join("/");
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
    try {
      return safeUrl(new URL(raw));
    } catch {
      return redactPath(raw.split(/[?#]/)[0]);
    }
  });
}
