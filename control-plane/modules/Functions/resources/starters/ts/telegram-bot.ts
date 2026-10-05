import { Hono } from 'hono';

// A Telegram bot over webhooks. Create a bot with @BotFather, then set in Variables:
//   TELEGRAM_BOT_TOKEN       the token from BotFather
//   TELEGRAM_WEBHOOK_SECRET  any random string (Telegram sends it back on every update)
// Then open https://<this function>/setup?secret=<TELEGRAM_WEBHOOK_SECRET> once to register the webhook.
const app = new Hono();
const api = (method: string) => `https://api.telegram.org/bot${process.env.TELEGRAM_BOT_TOKEN}/${method}`;

async function telegram(method: string, payload: Record<string, unknown>) {
    const res = await fetch(api(method), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    if (!res.ok) console.error(`telegram ${method} failed`, res.status, await res.text());
    return res;
}

function reply(text: string): string {
    if (text === '/start') return 'Hi! I run on a Falak function. Send me anything and I will echo it.';
    if (text === '/help') return 'Commands: /start, /help, /time. Anything else is echoed back.';
    if (text === '/time') return `It is ${new Date().toUTCString()}.`;
    return `You said: ${text}`;
}

app.post('/telegram', async (c) => {
    // Without a secret every caller would match: refuse instead.
    const secret = process.env.TELEGRAM_WEBHOOK_SECRET;
    if (!secret || c.req.header('X-Telegram-Bot-Api-Secret-Token') !== secret) {
        return c.json({ error: 'forbidden' }, 403);
    }
    const update = await c.req.json<{ message?: { chat: { id: number }; text?: string } }>();
    const message = update.message;
    if (message?.text) {
        await telegram('sendMessage', { chat_id: message.chat.id, text: reply(message.text.trim()) });
    }
    return c.json({ ok: true });
});

// Registers this function as the bot's webhook.
app.get('/setup', async (c) => {
    const secret = process.env.TELEGRAM_WEBHOOK_SECRET;
    if (!process.env.TELEGRAM_BOT_TOKEN || !secret) return c.json({ error: 'set TELEGRAM_BOT_TOKEN and TELEGRAM_WEBHOOK_SECRET' }, 500);
    if (c.req.query('secret') !== secret) return c.json({ error: 'forbidden' }, 403);
    const proto = c.req.header('X-Forwarded-Proto') ?? 'https';
    const url = `${proto}://${c.req.header('Host')}/telegram`;
    const res = await telegram('setWebhook', { url, secret_token: secret, allowed_updates: ['message'] });
    return c.json({ webhook: url, telegram: await res.json() });
});

app.get('/', (c) => c.text('Telegram bot: open /setup?secret=… once to register the webhook.'));

export default app;
