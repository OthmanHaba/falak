"""Secrets in URL paths never reach telemetry: the same rules as the JS runtimes (shared/redact.mjs), the Go runtime
and the function gateway. Query strings and userinfo are never recorded at all."""

from __future__ import annotations

import re

REDACTED = "{redacted}"

_TELEGRAM = re.compile(r"^bot\d+:[A-Za-z0-9_-]+$")  # api.telegram.org/bot<id>:<token>/sendMessage
_COLON_TOKEN = re.compile(r"^[A-Za-z0-9_-]+:[A-Za-z0-9_-]{16,}$")  # <id>:<secret>
_LONG = re.compile(r"^[A-Za-z0-9_:-]{32,}$")  # with a digit: API keys, webhook tokens, hashes, UUIDs
_MIXED = re.compile(r"^[A-Za-z0-9_-]{20,}$")  # shorter tokens: upper, lower and digits mixed
_DIGIT, _LOWER, _UPPER = re.compile(r"\d"), re.compile(r"[a-z]"), re.compile(r"[A-Z]")


def _classify(seg: str) -> str:
    if _TELEGRAM.match(seg):
        return "bot" + REDACTED
    if _COLON_TOKEN.match(seg) or (_LONG.match(seg) and _DIGIT.search(seg)):
        return REDACTED
    if _MIXED.match(seg) and _LOWER.search(seg) and _UPPER.search(seg) and _DIGIT.search(seg):
        return REDACTED
    return seg


_ESCAPE = re.compile(r"%([0-9A-Fa-f]{2})")


def _unescape(seg: str) -> str:
    """Percent-escapes decoded (a few rounds, for double encoding); malformed escapes are left as they are."""
    for _ in range(3):
        if "%" not in seg:
            break
        nxt = _ESCAPE.sub(lambda m: chr(int(m.group(1), 16)), seg)
        if nxt == seg:
            break
        seg = nxt
    return seg


def redact_segment(seg: str) -> str:
    """One path segment, or REDACTED when it looks like a secret. Escaped segments are judged decoded
    (bot123%3Aabc is bot123:abc), and kept as written when they are harmless."""
    plain = _unescape(seg)
    if plain == seg:
        return _classify(seg)
    verdict = _classify(plain)
    if verdict != plain:
        return verdict
    return REDACTED if any(_classify(p) != p for p in plain.split("/")) else seg


def redact_path(path: str) -> str:
    """/bot123:AAE…/sendMessage → /bot{redacted}/sendMessage."""
    return "/".join(redact_segment(s) for s in path.split("/"))


def safe_url(scheme: str, host: str, port: int | None, path: str) -> str:
    """scheme://host[:port]/path: no userinfo, no query, no fragment, secret segments redacted."""
    netloc = f"[{host}]" if ":" in host else host
    if port is not None:
        netloc += f":{port}"
    return f"{scheme}://{netloc}{redact_path(path or '/')}"


_URL_IN_TEXT = re.compile(r"[a-zA-Z][a-zA-Z0-9+.-]*://[^\s\"'<>`]+")
_ABSOLUTE = re.compile(r"^([a-zA-Z][a-zA-Z0-9+.-]*)://([^?#]*)")


def safe_raw_url(raw: str) -> str:
    """A URL no parser accepts (a bad port, an empty host), made safe all the same: everything up to the last "@"
    before the query is userinfo and dropped (a password may hold "@" or "/"), query and fragment go, the path is
    redacted."""
    m = _ABSOLUTE.match(raw)
    if not m:
        return redact_path(re.split(r"[?#]", raw)[0])
    rest = m.group(2).rpartition("@")[2]
    host, slash, path = rest.partition("/")
    return f"{m.group(1)}://{host}{redact_path(slash + path) if slash else '/'}"


def _safe_raw(raw: str) -> str:
    from urllib.parse import urlsplit

    try:
        u = urlsplit(raw)
        if not u.hostname:
            return safe_raw_url(raw)
        return safe_url(u.scheme, u.hostname, u.port, u.path)
    except ValueError:
        return safe_raw_url(raw)


def redact_text(text: str) -> str:
    """Free text (an error message) with every URL it quotes made safe."""
    if "://" not in text:
        return text
    return _URL_IN_TEXT.sub(lambda m: _safe_raw(m.group(0)), text)
