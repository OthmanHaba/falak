import { Hono } from 'hono';
import { cors } from 'hono/cors';

// Contact form backend: your site POSTs the form here and it is emailed to you through Resend (resend.com).
// Variables:
//   RESEND_API_KEY   your Resend API key
//   CONTACT_TO       where messages go, e.g. you@example.com
//   CONTACT_FROM     a sender on a domain verified in Resend, e.g. "Website <hello@example.com>"
//   ALLOWED_ORIGIN   your site's origin for CORS, e.g. https://example.com (default *)
//   REDIRECT_URL     where plain HTML form posts are sent afterwards (optional)
//
// <form method="post" action="https://<this function>/contact">
//   <input name="name"> <input name="email" type="email"> <textarea name="message"></textarea>
//   <input name="website" style="display:none"> <!-- honeypot: bots fill it -->
// </form>
const app = new Hono();

app.use('/contact', cors({ origin: process.env.ALLOWED_ORIGIN || '*' }));

const escape = (s: string) => s.replace(/[&<>"']/g, (ch) => `&#${ch.charCodeAt(0)};`);

app.post('/contact', async (c) => {
    const json = (c.req.header('Content-Type') ?? '').includes('application/json');
    const form: Record<string, string> = json ? await c.req.json().catch(() => ({})) : ((await c.req.parseBody()) as Record<string, string>);
    const name = String(form.name ?? '')
        .trim()
        .slice(0, 200);
    const email = String(form.email ?? '')
        .trim()
        .slice(0, 200);
    const message = String(form.message ?? '')
        .trim()
        .slice(0, 10_000);

    // Honeypot: pretend it worked.
    if (form.website) return json ? c.json({ ok: true }) : c.redirect(process.env.REDIRECT_URL || '/thanks');
    if (!name || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email) || message.length < 2) {
        return c.json({ error: 'name, a valid email and a message are required' }, 422);
    }
    if (!process.env.RESEND_API_KEY || !process.env.CONTACT_TO || !process.env.CONTACT_FROM) {
        return c.json({ error: 'set RESEND_API_KEY, CONTACT_TO and CONTACT_FROM' }, 500);
    }

    const res = await fetch('https://api.resend.com/emails', {
        method: 'POST',
        headers: { Authorization: `Bearer ${process.env.RESEND_API_KEY}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({
            from: process.env.CONTACT_FROM,
            to: process.env.CONTACT_TO,
            reply_to: email,
            subject: `Contact form: ${name}`,
            html: `<p><strong>${escape(name)}</strong> &lt;${escape(email)}&gt; wrote:</p><p>${escape(message).replaceAll('\n', '<br>')}</p>`,
        }),
    });
    if (!res.ok) {
        console.error('resend', res.status, await res.text());
        return c.json({ error: 'could not send the message' }, 502);
    }

    return json ? c.json({ ok: true }) : c.redirect(process.env.REDIRECT_URL || '/thanks');
});

app.get('/thanks', (c) => c.html('<p>Thanks! Your message was sent.</p>'));
app.get('/', (c) => c.text('Contact form backend: POST /contact'));

export default app;
