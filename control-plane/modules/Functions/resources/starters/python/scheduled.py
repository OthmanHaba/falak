# /// script
# dependencies = ["fastapi", "httpx"]
# ///
# Runs on its schedules (Schedules tab) in a one-shot container, and serves HTTP like any function.
# The event says which schedule fired: {"name", "schedule", "cron", "trigger": "cron" | "manual", "scheduled_time"}.
from datetime import datetime, timezone

import httpx
from fastapi import FastAPI


async def scheduled(event: dict):
    started = datetime.fromtimestamp(event["scheduled_time"] / 1000, timezone.utc).isoformat()
    print(f"{event['name']} ({event['trigger']}) started at {started}")

    # Do the work here: clean up rows, send a report, sync an API…
    async with httpx.AsyncClient(timeout=10) as client:
        print("zen:", (await client.get("https://api.github.com/zen")).text)


app = FastAPI()


@app.get("/")
def index():
    return {"ok": True, "hint": "This function also runs on a schedule: see its Schedules tab."}
