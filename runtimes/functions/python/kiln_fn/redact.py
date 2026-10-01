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


def redact_segment(seg: str) -> str:
    if _TELEGRAM.match(seg):
        return "bot" + REDACTED
    if _COLON_TOKEN.match(seg) or (_LONG.match(seg) and _DIGIT.search(seg)):
        return REDACTED
    if _MIXED.match(seg) and _LOWER.search(seg) and _UPPER.search(seg) and _DIGIT.search(seg):
        return REDACTED
    return seg


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


def _safe_raw(raw: str) -> str:
    from urllib.parse import urlsplit

    try:
        u = urlsplit(raw)
        return safe_url(u.scheme, u.hostname or "", u.port, u.path)
    except ValueError:
        return redact_path(re.split(r"[?#]", raw)[0])


def redact_text(text: str) -> str:
    """Free text (an error message) with every URL it quotes made safe."""
    if "://" not in text:
        return text
    return _URL_IN_TEXT.sub(lambda m: _safe_raw(m.group(0)), text)
