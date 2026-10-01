# /// script
# dependencies = ["fastapi", "httpx"]
# ///
# A caching proxy in front of another API: hides its key, adds CORS and caches GET responses.
# Variables:
#   UPSTREAM_URL            e.g. https://api.example.com
#   UPSTREAM_AUTHORIZATION  sent upstream as the Authorization header (optional, never exposed to browsers)
#   CACHE_SECONDS           how long GET responses are cached in memory (default 60)
#   ALLOWED_ORIGIN          CORS origin (default *)
# The cache lives in the instance's memory: it is per instance and empty after the function scales to zero.
import os
import time

import httpx
from fastapi import FastAPI, HTTPException, Request, Response
from fastapi.middleware.cors import CORSMiddleware

app = FastAPI()
app.add_middleware(CORSMiddleware, allow_origins=[os.environ.get("ALLOWED_ORIGIN") or "*"], allow_methods=["*"], allow_headers=["*"])
cache: dict[str, tuple[float, int, bytes, str]] = {}
client = httpx.AsyncClient(timeout=30)


@app.api_route("/{path:path}", methods=["GET", "POST", "PUT", "PATCH", "DELETE"])
async def proxy(path: str, request: Request):
    upstream = os.environ.get("UPSTREAM_URL")
    if not upstream:
        raise HTTPException(500, "UPSTREAM_URL is not set")

    target = upstream.rstrip("/") + "/" + path + (f"?{request.url.query}" if request.url.query else "")
    if request.method == "GET" and (hit := cache.get(target)) and hit[0] > time.time():
        return Response(hit[2], status_code=hit[1], media_type=hit[3], headers={"X-Cache": "HIT"})

    headers = {"Accept": request.headers.get("accept", "*/*")}
    if ct := request.headers.get("content-type"):
        headers["Content-Type"] = ct
    if auth := os.environ.get("UPSTREAM_AUTHORIZATION"):
        headers["Authorization"] = auth

    res = await client.request(request.method, target, headers=headers, content=await request.body() or None)
    media_type = res.headers.get("content-type", "application/octet-stream")
    if request.method == "GET" and res.is_success:
        cache[target] = (time.time() + float(os.environ.get("CACHE_SECONDS", "60")), res.status_code, res.content, media_type)
        if len(cache) > 1000:
            cache.pop(next(iter(cache)))
    return Response(res.content, status_code=res.status_code, media_type=media_type, headers={"X-Cache": "MISS"})
