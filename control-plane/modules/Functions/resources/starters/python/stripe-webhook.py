# /// script
# dependencies = ["fastapi"]
# ///
# Stripe webhook endpoint: verifies the Stripe-Signature header (no Stripe SDK needed) and handles events.
# In Stripe: Developers → Webhooks → Add endpoint → https://<this function>/stripe, then copy the signing secret
# (whsec_…) into STRIPE_WEBHOOK_SECRET in Variables.
import hashlib
import hmac
import json
import os
import time

from fastapi import FastAPI, HTTPException, Request

app = FastAPI()
TOLERANCE_SECONDS = 300


def verify(secret: str, body: bytes, header: str) -> bool:
    parts = [p.split("=", 1) for p in header.split(",") if "=" in p]
    timestamp = next((v for k, v in parts if k == "t"), None)
    signatures = [v for k, v in parts if k == "v1"]
    if not timestamp or not signatures or abs(time.time() - int(timestamp)) > TOLERANCE_SECONDS:
        return False
    expected = hmac.new(secret.encode(), f"{timestamp}.".encode() + body, hashlib.sha256).hexdigest()
    return any(hmac.compare_digest(expected, s) for s in signatures)


@app.post("/stripe")
async def stripe(request: Request):
    secret = os.environ.get("STRIPE_WEBHOOK_SECRET")
    if not secret:
        raise HTTPException(500, "STRIPE_WEBHOOK_SECRET is not set")

    body = await request.body()
    if not verify(secret, body, request.headers.get("Stripe-Signature", "")):
        raise HTTPException(400, "invalid signature")

    event = json.loads(body)
    obj = event["data"]["object"]
    if event["type"] == "checkout.session.completed":
        print("checkout completed", obj.get("id"), obj.get("customer_email"))  # fulfil the order here
    elif event["type"] == "invoice.payment_failed":
        print("payment failed", obj.get("customer"))
    elif event["type"] == "customer.subscription.deleted":
        print("subscription cancelled", obj.get("id"))
    else:
        print("unhandled event", event["type"])

    # Answer quickly: Stripe retries on errors and timeouts.
    return {"received": True}


@app.get("/")
def index():
    return "Stripe webhooks: POST /stripe"
