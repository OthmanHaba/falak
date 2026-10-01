import { Hono } from 'hono';

// Receives webhooks signed with HMAC-SHA256 (GitHub style: X-Hub-Signature-256: sha256=<hex>).
// Set WEBHOOK_SECRET in Variables and the same secret in the sender.
const app = new Hono();
const encoder = new TextEncoder();

async function hmacHex(secret: string, body: string): Promise<string> {
    const key = await crypto.subtle.importKey('raw', encoder.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
    const mac = new Uint8Array(await crypto.subtle.sign('HMAC', key, encoder.encode(body)));
    return Array.from(mac, (b) => b.toString(16).padStart(2, '0')).join('');
}

function safeEqual(a: string, b: string): boolean {
    if (a.length !== b.length) return false;
    let diff = 0;
    for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
    return diff === 0;
}

app.post('/webhook', async (c) => {
    // Without a secret anyone could sign with an empty key: refuse instead.
    const secret = process.env.WEBHOOK_SECRET;
    if (!secret) return c.json({ error: 'WEBHOOK_SECRET is not set' }, 500);

    const body = await c.req.text();
    const signature = c.req.header('X-Hub-Signature-256') ?? '';
    if (!safeEqual(signature, `sha256=${await hmacHex(secret, body)}`)) {
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
