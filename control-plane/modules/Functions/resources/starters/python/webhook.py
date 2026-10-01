# /// script
# dependencies = ["fastapi"]
# ///
# Receives webhooks signed with HMAC-SHA256 (GitHub style: X-Hub-Signature-256: sha256=<hex>).
# Set WEBHOOK_SECRET in Variables and the same secret in the sender.
import hashlib
import hmac
import json
import os

from fastapi import FastAPI, HTTPException, Request

app = FastAPI()


@app.post("/webhook")
async def webhook(request: Request):
    # Without a secret anyone could sign with an empty key: refuse instead.
    secret = os.environ.get("WEBHOOK_SECRET")
    if not secret:
        raise HTTPException(500, "WEBHOOK_SECRET is not set")

    body = await request.body()
    expected = "sha256=" + hmac.new(secret.encode(), body, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, request.headers.get("X-Hub-Signature-256", "")):
        raise HTTPException(401, "invalid signature")

    try:
        payload = json.loads(body)
    except ValueError:
        raise HTTPException(400, "invalid JSON")

    print("received", request.headers.get("X-GitHub-Event", "unknown"), payload)
    return {"ok": True}


@app.get("/")
def index():
    return "POST signed webhooks to /webhook"
