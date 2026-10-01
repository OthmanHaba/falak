# /// script
# dependencies = ["fastapi", "httpx"]
# ///
# A notification relay: POST {"title", "text", "level"} and it posts to Slack and/or Discord.
# Variables:
#   NOTIFY_TOKEN         callers send Authorization: Bearer <NOTIFY_TOKEN>
#   SLACK_WEBHOOK_URL    a Slack incoming webhook (optional)
#   DISCORD_WEBHOOK_URL  a Discord channel webhook (optional)
import hmac
import os

import httpx
from fastapi import FastAPI, Header, HTTPException
from pydantic import BaseModel

app = FastAPI()
ICONS = {"info": "ℹ️", "success": "✅", "warning": "⚠️", "error": "🚨"}


class Notification(BaseModel):
    text: str
    title: str | None = None
    level: str = "info"


@app.post("/notify")
async def notify(n: Notification, authorization: str = Header("")):
    token = os.environ.get("NOTIFY_TOKEN")
    if not token or not hmac.compare_digest(authorization, f"Bearer {token}"):
        raise HTTPException(401, "unauthorized")

    line = f"{ICONS.get(n.level, ICONS['info'])} " + (f"*{n.title}*\n" if n.title else "") + n.text
    sent = []
    async with httpx.AsyncClient(timeout=10) as client:
        if os.environ.get("SLACK_WEBHOOK_URL"):
            res = await client.post(os.environ["SLACK_WEBHOOK_URL"], json={"text": line})
            sent.append("slack") if res.is_success else print("slack", res.status_code, res.text)
        if os.environ.get("DISCORD_WEBHOOK_URL"):
            res = await client.post(os.environ["DISCORD_WEBHOOK_URL"], json={"content": line.replace("*", "**")})
            sent.append("discord") if res.is_success else print("discord", res.status_code, res.text)

    if not sent:
        raise HTTPException(500, "set SLACK_WEBHOOK_URL or DISCORD_WEBHOOK_URL")
    return {"sent": sent}


@app.get("/")
def index():
    return "POST /notify with Authorization: Bearer <NOTIFY_TOKEN>"
