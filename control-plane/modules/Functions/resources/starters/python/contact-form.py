# /// script
# dependencies = ["fastapi", "httpx", "python-multipart"]
# ///
# Contact form backend: your site POSTs the form here and it is emailed to you through Resend (resend.com).
# Variables:
#   RESEND_API_KEY   your Resend API key
#   CONTACT_TO       where messages go, e.g. you@example.com
#   CONTACT_FROM     a sender on a domain verified in Resend, e.g. "Website <hello@example.com>"
#   ALLOWED_ORIGIN   your site's origin for CORS, e.g. https://example.com (default *)
#   REDIRECT_URL     where plain HTML form posts are sent afterwards (optional)
import html
import os
import re

import httpx
from fastapi import FastAPI, HTTPException, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import HTMLResponse, RedirectResponse

app = FastAPI()
app.add_middleware(CORSMiddleware, allow_origins=[os.environ.get("ALLOWED_ORIGIN") or "*"], allow_methods=["POST"], allow_headers=["*"])
EMAIL = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")


@app.post("/contact")
async def contact(request: Request):
    is_json = "application/json" in request.headers.get("content-type", "")
    form = await request.json() if is_json else dict(await request.form())
    name = str(form.get("name", "")).strip()[:200]
    email = str(form.get("email", "")).strip()[:200]
    message = str(form.get("message", "")).strip()[:10_000]
    done = {"ok": True} if is_json else RedirectResponse(os.environ.get("REDIRECT_URL") or "/thanks", status_code=303)

    if form.get("website"):  # honeypot: bots fill it, pretend it worked
        return done
    if not name or not EMAIL.match(email) or len(message) < 2:
        raise HTTPException(422, "name, a valid email and a message are required")
    if not all(os.environ.get(k) for k in ("RESEND_API_KEY", "CONTACT_TO", "CONTACT_FROM")):
        raise HTTPException(500, "set RESEND_API_KEY, CONTACT_TO and CONTACT_FROM")

    async with httpx.AsyncClient(timeout=15) as client:
        res = await client.post(
            "https://api.resend.com/emails",
            headers={"Authorization": f"Bearer {os.environ['RESEND_API_KEY']}"},
            json={
                "from": os.environ["CONTACT_FROM"],
                "to": os.environ["CONTACT_TO"],
                "reply_to": email,
                "subject": f"Contact form: {name}",
                "html": f"<p><strong>{html.escape(name)}</strong> &lt;{html.escape(email)}&gt; wrote:</p><p>{html.escape(message).replace(chr(10), '<br>')}</p>",
            },
        )
    if res.is_error:
        print("resend", res.status_code, res.text)
        raise HTTPException(502, "could not send the message")
    return done


@app.get("/thanks", response_class=HTMLResponse)
def thanks():
    return "<p>Thanks! Your message was sent.</p>"


@app.get("/")
def index():
    return "Contact form backend: POST /contact"
