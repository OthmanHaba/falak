# Kiln function runtime: Python

The image Kiln runs Python functions with (`fn.release.apply` → `kiln-fn-gateway`). It's based on
`python:3.13-slim`, with `uv` for dependencies and `uvicorn` for serving. Build context: `runtimes/functions`, file
`python/Dockerfile`.

```python
# /// script
# dependencies = ["fastapi", "httpx"]
# ///
import httpx
from fastapi import FastAPI

app = FastAPI()


@app.get("/hello/{name}")
async def hello(name: str):
    return {"message": f"Hello, {name}!"}


async def scheduled(event):  # optional: runs on the function's schedules
    async with httpx.AsyncClient() as client:
        await client.post("https://example.com/hooks/nightly", json={"run": event["name"]})
```

## Convention

It follows the same commands as every function runtime (see `../bun/README.md`). The agent and the gateway only
rely on these commands.

| Command | Does |
|---|---|
| `kiln-fn-install` | Creates `/app/.venv` (with `--system-site-packages`, so the image's uvicorn is visible) and installs the dependencies into it. |
| `kiln-fn-serve` | Imports `/app/$KILN_ENTRYPOINT` (default `main.py`) and serves its **`app`** with uvicorn on `0.0.0.0:$PORT`. It listens only once the module is imported, so an accepted connection means ready. One worker; stops gracefully on SIGTERM. |
| `kiln-fn-run` | Imports the module and calls **`scheduled(event)`**, plain or `async`. |

**What `app` can be:** any ASGI application, such as FastAPI, Starlette, Litestar, Quart, or a plain
`async def app(scope, receive, send)`.

**The `event` passed to `scheduled`:** `{"name", "schedule", "cron", "trigger": "cron" | "manual",
"scheduled_time"}`, where `scheduled_time` is in milliseconds since the epoch.

**Exit status of a run:** 0 when `scheduled` returns, 1 when it raises. The traceback is printed and reported.

The release is read-only at serve and run time. `PYTHONDONTWRITEBYTECODE` is set, so nothing is written outside
`/tmp`.

## Dependencies

You declare them in either of two places (both work together):

- a **PEP 723** block at the top of the entrypoint:

  ```python
  # /// script
  # dependencies = ["fastapi>=0.115", "psycopg[binary]"]
  # ///
  ```

- a **`requirements.txt`** among the function's files.

With several files, every `.py` file may have its own `# /// script` block: the entrypoint's comes first and the
others are merged into it (duplicates removed).

The first install resolves them with `uv pip compile` into `/app/requirements.lock` and installs exactly that. The
agent keeps that lock per code version and puts it back for later releases of the same code (a redeploy, a
scaling change, a rollback), so the same code always gets the same versions. When the lock is present, nothing is
resolved again. Packages are cached in `/cache/uv`, shared by every release on the server.

A function without dependencies still gets an (empty) virtualenv.

## Telemetry

When `$KILN_OTLP_SOCKET` exists, the runtime reports to it, with no package needed (`kiln_fn/telemetry.py`). It
uses the same encoding as the Bun runtime and follows `contracts/telemetry/README.md`.

- **Requests:** a `request` span per HTTP request.
  - It is named by the route template from Starlette/FastAPI routing, e.g. `GET /hello/{name}`.
  - Apps without that routing get the path with ids replaced, e.g. `/users/:id`.
  - Paths no route matches are grouped as `(unmatched)`.
  - Each span records the status code and `faas.coldstart` (from the gateway's `X-Kiln-Cold-Start` header).
- **Exceptions** that escape the app: an `exception` event with the stack trace, and ERROR status. FastAPI still
  answers 500 as usual.
- **Outgoing HTTP:** `outgoing_request` spans for `httpx` (sync and async) and `requests`, under the request or run
  that made them. No query strings or userinfo, and secret-looking path segments are redacted (`kiln_fn/redact.py`,
  the same rules as the other runtimes).
- **Scheduled runs:** a `scheduled_task` span per run, `finished` or `failed`.

Spans go out in batches from a background thread: every second, or every 256 spans. They never block a request.
When the socket is down, the spans are dropped. Set `KILN_TELEMETRY=off` to turn it off.
