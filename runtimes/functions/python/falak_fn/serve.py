"""falak-fn-serve: loads /app/$FALAK_ENTRYPOINT and serves its `app` (an ASGI app: FastAPI, Starlette, …) with
uvicorn on 0.0.0.0:$PORT.

The gateway treats the first accepted connection as "ready", so the server only listens once the module has
loaded. uvicorn stops gracefully on SIGTERM; queued telemetry is flushed on exit.
"""

from __future__ import annotations

import os
import sys

# First, so it is ready before the function's module runs.
from falak_fn import telemetry
from falak_fn.load import entrypoint, load


def main() -> None:
    module = load()
    app = getattr(module, "app", None)
    if app is None or not callable(app):
        print(
            f"falak: {entrypoint()} must define `app`, an ASGI app (e.g. `app = FastAPI()`)",
            file=sys.stderr,
        )
        sys.exit(1)
    telemetry.instrument_http_clients()

    import uvicorn

    port = int(os.environ.get("PORT", "8080"))
    config = uvicorn.Config(
        telemetry.Telemetry(app),
        host="0.0.0.0",
        port=port,
        workers=1,
        log_level="warning",
        access_log=False,
        lifespan="auto",
        server_header=False,
        # Only the gateway reaches the instance: its X-Forwarded-* carry the visitor.
        proxy_headers=True,
        forwarded_allow_ips="*",
        timeout_graceful_shutdown=10,
    )
    print(f"falak: {entrypoint()} listening on :{port}", flush=True)
    uvicorn.Server(config).run()


if __name__ == "__main__":
    main()
