"""kiln-fn-run: one scheduled run of a function. Loads /app/$KILN_ENTRYPOINT and calls its scheduled handler:

    async def scheduled(event): …      # or a plain `def scheduled(event)`

event = {"name", "schedule", "cron", "trigger": "cron" | "manual", "scheduled_time"} (scheduled_time in ms since
the epoch). Exit 0 when it returns, 1 when it raises (the error and its traceback are printed and reported).
"""

from __future__ import annotations

import asyncio
import inspect
import os
import sys
import time
import traceback

from kiln_fn import telemetry
from kiln_fn.load import entrypoint, load


def main() -> None:
    schedule = os.environ.get("KILN_SCHEDULE", "")
    name = os.environ.get("KILN_SCHEDULE_NAME") or schedule
    cron = os.environ.get("KILN_SCHEDULE_CRON", "")
    trigger = "manual" if os.environ.get("KILN_TRIGGER") == "manual" else "cron"

    module = load()
    handler = getattr(module, "scheduled", None)
    if not callable(handler):
        print(f"kiln: {entrypoint()} has no scheduled handler: add `async def scheduled(event): …`", file=sys.stderr)
        sys.exit(1)
    telemetry.instrument_http_clients()

    event = {"name": name, "schedule": schedule, "cron": cron, "trigger": trigger, "scheduled_time": int(time.time() * 1000)}
    print(f"kiln: running {name} ({trigger}{', ' + cron if cron else ''})", flush=True)
    started = time.perf_counter()
    span = telemetry.scheduled_span(name, cron)
    token = telemetry.current.set(span)
    code, error = 0, None
    try:
        result = handler(event)
        if inspect.isawaitable(result):
            asyncio.run(_await(result))
        print(f"kiln: {name} finished in {round((time.perf_counter() - started) * 1000)}ms", flush=True)
    except Exception as err:
        error, code = err, 1
        print(f"kiln: {name} failed: {err}", file=sys.stderr)
        traceback.print_exc()
    finally:
        telemetry.current.reset(token)
        telemetry.end_scheduled(span, error)
        telemetry.flush()
    sys.exit(code)


async def _await(awaitable):  # asyncio.run needs a coroutine; handlers may return any awaitable
    return await awaitable


if __name__ == "__main__":
    main()
