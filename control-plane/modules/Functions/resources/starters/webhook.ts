import { Hono } from 'hono';
import { timingSafeEqual } from 'node:crypto';

// Receives webhooks signed with HMAC-SHA256 (GitHub style: X-Hub-Signature-256: sha256=<hex>).
// Set WEBHOOK_SECRET in Variables.
const app = new Hono();

async function verify(secret: string, body: string, signature: string | undefined): Promise<boolean> {
    if (!signature?.startsWith('sha256=')) return false;
    const key = await crypto.subtle.importKey('raw', new TextEncoder().encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
    const mac = new Uint8Array(await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(body)));
    const expected = 'sha256=' + Array.from(mac, (b) => b.toString(16).padStart(2, '0')).join('');
    return expected.length === signature.length && timingSafeEqual(new TextEncoder().encode(expected), new TextEncoder().encode(signature));
}

app.post('/webhook', async (c) => {
    // Without a secret anyone could sign with an empty key: refuse instead.
    const secret = process.env.WEBHOOK_SECRET;
    if (!secret) return c.json({ error: 'WEBHOOK_SECRET is not set' }, 500);

    const body = await c.req.text();
    if (!(await verify(secret, body, c.req.header('X-Hub-Signature-256')))) {
        return c.json({ error: 'invalid signature' }, 401);
    }

    let payload: unknown;
    try {
        payload = JSON.parse(body);
    } catch {
        return c.json({ error: 'invalid JSON' }, 400);
    }

    const event = c.req.header('X-GitHub-Event') ?? 'unknown';
    console.log(`received ${event}`, payload);
    return c.json({ ok: true });
});

app.get('/', (c) => c.text('POST signed webhooks to /webhook'));

export default app;
