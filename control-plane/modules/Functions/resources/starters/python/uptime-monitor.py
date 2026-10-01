# /// script
# dependencies = ["fastapi", "httpx"]
# ///
# Checks your URLs on a schedule (every 5 minutes) and alerts when one is down.
# Variables:
#   URLS               comma-separated URLs to check, e.g. https://example.com,https://api.example.com/health
#   ALERT_WEBHOOK_URL  a Slack or Discord webhook for alerts (optional: failures are also logged)
# GET /status runs the checks now and returns the results.
import asyncio
import os
import time

import httpx
from fastapi import FastAPI

TIMEOUT = 10


async def check(client: httpx.AsyncClient, url: str) -> dict:
    started = time.perf_counter()
    try:
        res = await client.get(url, follow_redirects=True)
        return {"url": url, "ok": res.status_code < 400, "status": res.status_code, "ms": round((time.perf_counter() - started) * 1000)}
    except httpx.HTTPError as err:
        return {"url": url, "ok": False, "status": None, "ms": round((time.perf_counter() - started) * 1000), "error": str(err)}


async def check_all() -> list[dict]:
    urls = [u.strip() for u in os.environ.get("URLS", "").split(",") if u.strip()]
    async with httpx.AsyncClient(timeout=TIMEOUT) as client:
        return await asyncio.gather(*(check(client, u) for u in urls))


async def alert(down: list[dict]):
    text = f"🚨 {len(down)} URL(s) down:\n" + "\n".join(f"• {r['url']}: {r['status'] or r.get('error')} ({r['ms']}ms)" for r in down)
    print(text)
    if hook := os.environ.get("ALERT_WEBHOOK_URL"):
        async with httpx.AsyncClient(timeout=TIMEOUT) as client:
            # Slack reads `text`, Discord reads `content`.
            await client.post(hook, json={"text": text, "content": text})


async def scheduled(event: dict):
    results = await check_all()
    if not results:
        print("URLS is empty: nothing to check")
        return
    down = [r for r in results if not r["ok"]]
    print(f"{len(results) - len(down)}/{len(results)} up")
    if down:
        await alert(down)
        # A failed run shows up in Observability → Scheduled tasks and opens an issue.
        raise RuntimeError(f"{len(down)} URL(s) down: " + ", ".join(r["url"] for r in down))


app = FastAPI()


@app.get("/status")
async def status():
    results = await check_all()
    return {"up": all(r["ok"] for r in results), "results": results}


@app.get("/")
def index():
    return "Uptime monitor: runs every 5 minutes. GET /status checks now."
