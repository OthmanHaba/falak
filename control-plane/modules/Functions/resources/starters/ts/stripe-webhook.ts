import { Hono } from 'hono';

// Stripe webhook endpoint: verifies the Stripe-Signature header (no Stripe SDK needed) and handles events.
// In Stripe: Developers → Webhooks → Add endpoint → https://<this function>/stripe, then copy the signing secret
// (whsec_…) into STRIPE_WEBHOOK_SECRET in Variables.
const app = new Hono();
const encoder = new TextEncoder();
const TOLERANCE_SECONDS = 300;

async function hmacHex(secret: string, payload: string): Promise<string> {
    const key = await crypto.subtle.importKey('raw', encoder.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
    const mac = new Uint8Array(await crypto.subtle.sign('HMAC', key, encoder.encode(payload)));
    return Array.from(mac, (b) => b.toString(16).padStart(2, '0')).join('');
}

function safeEqual(a: string, b: string): boolean {
    if (a.length !== b.length) return false;
    let diff = 0;
    for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
    return diff === 0;
}

async function verify(secret: string, body: string, header: string): Promise<boolean> {
    const parts = header.split(',').map((p) => p.split('=') as [string, string]);
    const timestamp = parts.find(([k]) => k === 't')?.[1];
    const signatures = parts.filter(([k]) => k === 'v1').map(([, v]) => v);
    if (!timestamp || signatures.length === 0) return false;
    if (Math.abs(Date.now() / 1000 - Number(timestamp)) > TOLERANCE_SECONDS) return false;
    const expected = await hmacHex(secret, `${timestamp}.${body}`);
    return signatures.some((s) => safeEqual(s, expected));
}

type StripeEvent = { id: string; type: string; data: { object: Record<string, unknown> } };

app.post('/stripe', async (c) => {
    const secret = process.env.STRIPE_WEBHOOK_SECRET;
    if (!secret) return c.json({ error: 'STRIPE_WEBHOOK_SECRET is not set' }, 500);

    const body = await c.req.text();
    if (!(await verify(secret, body, c.req.header('Stripe-Signature') ?? ''))) {
        return c.json({ error: 'invalid signature' }, 400);
    }

    const event = JSON.parse(body) as StripeEvent;
    switch (event.type) {
        case 'checkout.session.completed':
            console.log('checkout completed', event.data.object.id, event.data.object.customer_email);
            // Fulfil the order here.
            break;
        case 'invoice.payment_failed':
            console.warn('payment failed', event.data.object.customer);
            break;
        case 'customer.subscription.deleted':
            console.log('subscription cancelled', event.data.object.id);
            break;
        default:
            console.log('unhandled event', event.type);
    }

    // Answer quickly: Stripe retries on errors and timeouts.
    return c.json({ received: true });
});

app.get('/', (c) => c.text('Stripe webhooks: POST /stripe'));

export default app;
