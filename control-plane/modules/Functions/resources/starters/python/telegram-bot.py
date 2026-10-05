# /// script
# dependencies = ["fastapi", "httpx"]
# ///
# A Telegram bot over webhooks. Create a bot with @BotFather, then set in Variables:
#   TELEGRAM_BOT_TOKEN       the token from BotFather
#   TELEGRAM_WEBHOOK_SECRET  any random string (Telegram sends it back on every update)
# Then open https://<this function>/setup?secret=<TELEGRAM_WEBHOOK_SECRET> once to register the webhook.
import os
from datetime import datetime, timezone

import httpx
from fastapi import FastAPI, HTTPException, Request

app = FastAPI()


async def telegram(method: str, payload: dict):
    async with httpx.AsyncClient(timeout=10) as client:
        res = await client.post(f"https://api.telegram.org/bot{os.environ.get('TELEGRAM_BOT_TOKEN')}/{method}", json=payload)
        if res.is_error:
            print(f"telegram {method} failed", res.status_code, res.text)
        return res


def reply(text: str) -> str:
    if text == "/start":
        return "Hi! I run on a Falak function. Send me anything and I will echo it."
    if text == "/help":
        return "Commands: /start, /help, /time. Anything else is echoed back."
    if text == "/time":
        return f"It is {datetime.now(timezone.utc):%a, %d %b %Y %H:%M:%S} UTC."
    return f"You said: {text}"


@app.post("/telegram")
async def update(request: Request):
    # Without a secret every caller would match: refuse instead.
    secret = os.environ.get("TELEGRAM_WEBHOOK_SECRET")
    if not secret or request.headers.get("X-Telegram-Bot-Api-Secret-Token") != secret:
        raise HTTPException(403, "forbidden")
    message = (await request.json()).get("message") or {}
    if message.get("text"):
        await telegram("sendMessage", {"chat_id": message["chat"]["id"], "text": reply(message["text"].strip())})
    return {"ok": True}


@app.get("/setup")
async def setup(request: Request, secret: str = ""):
    expected = os.environ.get("TELEGRAM_WEBHOOK_SECRET")
    if not os.environ.get("TELEGRAM_BOT_TOKEN") or not expected:
        raise HTTPException(500, "set TELEGRAM_BOT_TOKEN and TELEGRAM_WEBHOOK_SECRET")
    if secret != expected:
        raise HTTPException(403, "forbidden")
    url = f"{request.headers.get('X-Forwarded-Proto', 'https')}://{request.headers['host']}/telegram"
    res = await telegram("setWebhook", {"url": url, "secret_token": expected, "allowed_updates": ["message"]})
    return {"webhook": url, "telegram": res.json()}


@app.get("/")
def index():
    return "Telegram bot: open /setup?secret=… once to register the webhook."
