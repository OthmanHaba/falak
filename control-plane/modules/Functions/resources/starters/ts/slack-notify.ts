import { Hono } from 'hono';
import { bearerAuth } from 'hono/bearer-auth';

// A notification relay: POST {"title", "text", "level"} and it posts to Slack and/or Discord.
// Variables:
//   NOTIFY_TOKEN         callers send Authorization: Bearer <NOTIFY_TOKEN>
//   SLACK_WEBHOOK_URL    a Slack incoming webhook (optional)
//   DISCORD_WEBHOOK_URL  a Discord channel webhook (optional)
const app = new Hono();
const icons: Record<string, string> = { info: 'ℹ️', success: '✅', warning: '⚠️', error: '🚨' };

app.use('/notify', bearerAuth({ token: process.env.NOTIFY_TOKEN || crypto.randomUUID() }));

app.post('/notify', async (c) => {
    const input = await c.req.json<{ title?: string; text?: string; level?: string }>().catch(() => null);
    if (!input?.text) return c.json({ error: 'send {"text": "…", "title"?: "…", "level"?: "info|success|warning|error"}' }, 422);

    const icon = icons[input.level ?? 'info'] ?? icons.info;
    const line = `${icon} ${input.title ? `*${input.title}*\n` : ''}${input.text}`;
    const sent: string[] = [];

    if (process.env.SLACK_WEBHOOK_URL) {
        const res = await fetch(process.env.SLACK_WEBHOOK_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text: line }),
        });
        if (res.ok) sent.push('slack');
        else console.error('slack', res.status, await res.text());
    }

    if (process.env.DISCORD_WEBHOOK_URL) {
        const res = await fetch(process.env.DISCORD_WEBHOOK_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ content: line.replaceAll('*', '**') }),
        });
        if (res.ok) sent.push('discord');
        else console.error('discord', res.status, await res.text());
    }

    if (sent.length === 0) return c.json({ error: 'set SLACK_WEBHOOK_URL or DISCORD_WEBHOOK_URL' }, 500);
    return c.json({ sent });
});

app.get('/', (c) => c.text('POST /notify with Authorization: Bearer <NOTIFY_TOKEN>'));

export default app;
