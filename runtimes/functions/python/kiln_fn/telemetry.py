"""Built-in telemetry of Kiln functions (Python): request spans named by the route template, exceptions, outgoing
HTTP calls (httpx, requests) and scheduled runs, following the Kiln telemetry contract
(contracts/telemetry/README.md) and encoded like the Bun runtime's telemetry.ts.

No dependencies, so cold starts stay fast. Spans are batched by a background thread and sent as OTLP/HTTP JSON to
the function's socket ($KILN_OTLP_SOCKET), which the gateway relays to the agent under the function's identity.
Telemetry never blocks or fails a request: a full queue drops spans, a failed send drops the batch.
"""

from __future__ import annotations

import atexit
import contextvars
import http.client
import json
import os
import re
import secrets
import socket
import threading
import time
import traceback
from typing import Any, Awaitable, Callable

SERVER, CLIENT, INTERNAL = 2, 3, 1
ERROR = 2
FLUSH_SECONDS = 1.0
MAX_BATCH = 256
MAX_QUEUE = 4096
UNMATCHED = "(unmatched)"

SOCKET = os.environ.get("KILN_OTLP_SOCKET", "")
ENABLED = SOCKET != "" and os.environ.get("KILN_TELEMETRY") != "off" and os.path.exists(SOCKET)

current: contextvars.ContextVar[dict | None] = contextvars.ContextVar("kiln_span", default=None)

_queue: list[dict] = []
_lock = threading.Lock()
_wake = threading.Event()
_send_lock = threading.Lock()
_thread: threading.Thread | None = None


def _hex(n: int) -> str:
    return secrets.token_hex(n)


def _nanos() -> str:
    return str(time.time_ns())


def _str(key: str, v: str) -> dict:
    return {"key": key, "value": {"stringValue": v}}


def _int(key: str, v: int) -> dict:
    return {"key": key, "value": {"intValue": str(int(v))}}


def _bool(key: str, v: bool) -> dict:
    return {"key": key, "value": {"boolValue": v}}


def new_span(name: str, kind: int, attributes: list[dict], parent: dict | None = None) -> dict:
    span = {
        "traceId": parent["traceId"] if parent else _hex(16),
        "spanId": _hex(8),
        "name": name,
        "kind": kind,
        "startTimeUnixNano": _nanos(),
        "attributes": attributes,
        "events": [],
    }
    if parent:
        span["parentSpanId"] = parent["spanId"]
    return span


def exception_event(err: BaseException, handled: bool) -> dict:
    cls = type(err)
    name = cls.__qualname__ if cls.__module__ in ("builtins", "__main__") else f"{cls.__module__}.{cls.__qualname__}"
    return {
        "name": "exception",
        "timeUnixNano": _nanos(),
        "attributes": [
            _str("exception.type", name),
            _str("exception.message", str(err)[:4096]),
            _str("exception.stacktrace", "".join(traceback.format_exception(err))[:16384]),
            _bool("kiln.exception.handled", handled),
        ],
    }


def record_exception(err: BaseException, handled: bool = False) -> None:
    """Records an exception on the current request or run (handled = your code caught it)."""
    span = current.get()
    if span is None:
        return
    span["events"].append(exception_event(err, handled))
    if not handled:
        span["status"] = {"code": ERROR, "message": str(err)[:512]}


def finish(span: dict) -> None:
    if not ENABLED:
        return
    span["endTimeUnixNano"] = _nanos()
    with _lock:
        if len(_queue) >= MAX_QUEUE:
            return  # agent down: drop rather than grow
        _queue.append(span)
        full = len(_queue) >= MAX_BATCH
    _ensure_thread()
    if full:
        _wake.set()


# ---- export -------------------------------------------------------------------------------------------------------


class _UnixConnection(http.client.HTTPConnection):
    def __init__(self, path: str, timeout: float) -> None:
        super().__init__("kiln", timeout=timeout)
        self._path = path

    def connect(self) -> None:
        sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        sock.settimeout(self.timeout)
        sock.connect(self._path)
        self.sock = sock


def _post(batch: list[dict]) -> bool:
    body = json.dumps(
        {
            "resourceSpans": [
                {
                    "resource": {
                        "attributes": [
                            _str("telemetry.sdk.name", "kiln-fn"),
                            _str("telemetry.sdk.language", "python"),
                            _str("process.runtime.name", "cpython"),
                        ]
                    },
                    "scopeSpans": [{"scope": {"name": "kiln-fn-python"}, "spans": batch}],
                }
            ]
        },
        separators=(",", ":"),
    ).encode()
    try:
        conn = _UnixConnection(SOCKET, timeout=2.0)
        conn.request("POST", "/v1/traces", body=body, headers={"Content-Type": "application/json"})
        conn.getresponse().read()
        conn.close()
        return True
    except OSError:
        return False


def flush() -> None:
    """Sends everything queued (also called on exit). On a failed send the rest is dropped: a shutdown must not
    outlast the stop grace period."""
    if not ENABLED:
        return
    with _send_lock:
        while True:
            with _lock:
                batch = _queue[:MAX_BATCH]
                del _queue[:MAX_BATCH]
            if not batch:
                return
            if not _post(batch):
                with _lock:
                    _queue.clear()
                return


def _loop() -> None:
    while True:
        _wake.wait(FLUSH_SECONDS)
        _wake.clear()
        flush()


def _ensure_thread() -> None:
    global _thread
    if _thread is None:
        with _lock:
            if _thread is None:
                _thread = threading.Thread(target=_loop, name="kiln-telemetry", daemon=True)
                _thread.start()


if ENABLED:
    atexit.register(flush)


# ---- requests -----------------------------------------------------------------------------------------------------

_SEGMENT = re.compile(r"^\d+$|^[0-9a-f-]{16,}$|^[A-Za-z0-9_-]{24,}$", re.IGNORECASE)


def normalize(path: str) -> str:
    """/users/42 → /users/:id (numbers, UUIDs, long hex or base64-ish segments), for apps without a router."""
    return "/".join(":id" if _SEGMENT.match(seg) else seg for seg in path.split("/")) or "/"


def _is_starlette(app: Any) -> bool:
    return any(c.__module__.startswith(("starlette.", "fastapi.")) for c in type(app).__mro__)


class Telemetry:
    """ASGI middleware: one `request` span per HTTP request around the function's app."""

    def __init__(self, app: Callable[..., Awaitable[None]]) -> None:
        self.app = app
        self.router = _is_starlette(app)

    async def __call__(self, scope: dict, receive: Callable, send: Callable) -> None:
        if scope["type"] != "http" or not ENABLED:
            await self.app(scope, receive, send)
            return

        headers = {k.decode("latin-1").lower(): v.decode("latin-1") for k, v in scope.get("headers", [])}
        method = scope.get("method", "GET")
        path = scope.get("path", "/")
        span = new_span(
            method,
            SERVER,
            [
                _str("kiln.event.type", "request"),
                _str("http.request.method", method),
                _str("url.path", path),
                _bool("faas.coldstart", headers.get("x-kiln-cold-start") == "1"),
            ],
        )
        parent = re.fullmatch(r"00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}", headers.get("traceparent", ""))
        if parent:
            span["traceId"], span["parentSpanId"] = parent.group(1), parent.group(2)

        status = {"code": None}

        async def send_wrapper(message: dict) -> None:
            if message["type"] == "http.response.start":
                status["code"] = message["status"]
            await send(message)

        token = current.set(span)
        try:
            await self.app(scope, receive, send_wrapper)
        except BaseException as err:  # Starlette's ServerErrorMiddleware answers 500 and re-raises
            span["events"].append(exception_event(err, False))
            span["status"] = {"code": ERROR, "message": str(err)[:512]}
            if status["code"] is None:
                status["code"] = 500
            raise
        finally:
            current.reset(token)
            route = getattr(scope.get("route"), "path", None)
            if not route:
                route = UNMATCHED if self.router else normalize(path)
            span["name"] = f"{method} {route}"
            span["attributes"].append(_str("http.route", route))
            code = status["code"] if status["code"] is not None else 500
            span["attributes"].append(_int("http.response.status_code", code))
            if code >= 500 and "status" not in span:
                span["status"] = {"code": ERROR}
            finish(span)


# ---- outgoing HTTP ------------------------------------------------------------------------------------------------


def _client_span(method: str, url: Any) -> dict | None:
    parent = current.get()
    if parent is None or not ENABLED:
        return None
    host = getattr(url, "netloc", "")  # httpx: bytes, requests (_Url): str
    if isinstance(host, bytes):
        host = host.decode("latin-1")
    scheme, path = str(url.scheme), str(url.path) or "/"
    hostname = str(getattr(url, "host", "") or host.split(":")[0])
    return new_span(
        f"{method.upper()} {host}",
        CLIENT,
        [
            _str("kiln.event.type", "outgoing_request"),
            _str("http.request.method", method.upper()),
            _str("url.full", f"{scheme}://{host}{path}"),
            _str("server.address", hostname),
        ],
        parent,
    )


def _end_client(span: dict, response: Any = None, err: BaseException | None = None) -> None:
    if response is not None:
        code = int(response.status_code)
        span["attributes"].append(_int("http.response.status_code", code))
        if code >= 500:
            span["status"] = {"code": ERROR}
    if err is not None:
        span["events"].append(exception_event(err, True))
        span["status"] = {"code": ERROR, "message": str(err)[:512]}
    finish(span)


class _Url:
    """requests' URLs are strings: the parts _client_span needs."""

    def __init__(self, url: str) -> None:
        from urllib.parse import urlsplit

        u = urlsplit(url)
        self.scheme, self.netloc, self.path, self.host = u.scheme, u.netloc, u.path or "/", u.hostname or ""


def instrument_http_clients() -> None:
    """Patches httpx and requests when the function uses them (call after importing it)."""
    if not ENABLED:
        return
    try:
        import httpx

        sync_send, async_send = httpx.Client.send, httpx.AsyncClient.send

        def send(self, request, *args, **kwargs):  # type: ignore[no-untyped-def]
            span = _client_span(request.method, request.url)
            if span is None:
                return sync_send(self, request, *args, **kwargs)
            try:
                response = sync_send(self, request, *args, **kwargs)
            except BaseException as err:
                _end_client(span, err=err)
                raise
            _end_client(span, response)
            return response

        async def asend(self, request, *args, **kwargs):  # type: ignore[no-untyped-def]
            span = _client_span(request.method, request.url)
            if span is None:
                return await async_send(self, request, *args, **kwargs)
            try:
                response = await async_send(self, request, *args, **kwargs)
            except BaseException as err:
                _end_client(span, err=err)
                raise
            _end_client(span, response)
            return response

        httpx.Client.send, httpx.AsyncClient.send = send, asend
    except ImportError:
        pass

    try:
        import requests

        session_send = requests.Session.send

        def rsend(self, request, **kwargs):  # type: ignore[no-untyped-def]
            span = _client_span(request.method or "GET", _Url(request.url))
            if span is None:
                return session_send(self, request, **kwargs)
            try:
                response = session_send(self, request, **kwargs)
            except BaseException as err:
                _end_client(span, err=err)
                raise
            _end_client(span, response)
            return response

        requests.Session.send = rsend
    except ImportError:
        pass


# ---- scheduled runs -----------------------------------------------------------------------------------------------


def scheduled_span(name: str, expression: str) -> dict:
    return new_span(
        f"schedule {name}",
        INTERNAL,
        [_str("kiln.event.type", "scheduled_task"), _str("kiln.schedule.name", name), _str("kiln.schedule.expression", expression)],
    )


def end_scheduled(span: dict, err: BaseException | None) -> None:
    span["attributes"].append(_str("kiln.schedule.status", "failed" if err else "finished"))
    if err is not None:
        span["events"].append(exception_event(err, False))
        span["status"] = {"code": ERROR, "message": str(err)[:512]}
    finish(span)
