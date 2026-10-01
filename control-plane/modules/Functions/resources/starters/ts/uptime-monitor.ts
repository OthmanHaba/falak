import { Hono } from 'hono';

// Checks your URLs on a schedule (every 5 minutes) and alerts when one is down.
// Variables:
//   URLS               comma-separated URLs to check, e.g. https://example.com,https://api.example.com/health
//   ALERT_WEBHOOK_URL  a Slack or Discord webhook for alerts (optional: failures are also logged)
// GET /status runs the checks now and returns the results.
const TIMEOUT_MS = 10_000;

type Result = { url: string; ok: boolean; status: number | null; ms: number; error?: string };

async function check(url: string): Promise<Result> {
    const started = performance.now();
    try {
        const res = await fetch(url, { redirect: 'follow', signal: AbortSignal.timeout(TIMEOUT_MS) });
        return { url, ok: res.status < 400, status: res.status, ms: Math.round(performance.now() - started) };
    } catch (err) {
        return { url, ok: false, status: null, ms: Math.round(performance.now() - started), error: (err as Error).message };
    }
}

async function checkAll(): Promise<Result[]> {
    const urls = (process.env.URLS ?? '')
        .split(',')
        .map((u) => u.trim())
        .filter(Boolean);
    return Promise.all(urls.map(check));
}

async function alert(down: Result[]) {
    const text = `🚨 ${down.length} URL(s) down:\n` + down.map((r) => `• ${r.url}: ${r.status ?? r.error} (${r.ms}ms)`).join('\n');
    console.error(text);
    const hook = process.env.ALERT_WEBHOOK_URL;
    if (!hook) return;
    await fetch(hook, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        // Slack reads `text`, Discord reads `content`.
        body: JSON.stringify({ text, content: text }),
    });
}

export async function scheduled() {
    const results = await checkAll();
    if (results.length === 0) {
        console.warn('URLS is empty: nothing to check');
        return;
    }
    const down = results.filter((r) => !r.ok);
    console.log(`${results.length - down.length}/${results.length} up`);
    if (down.length > 0) {
        await alert(down);
        // A failed run shows up in Observability → Scheduled tasks and opens an issue.
        throw new Error(`${down.length} URL(s) down: ${down.map((r) => r.url).join(', ')}`);
    }
}

const app = new Hono();

app.get('/status', async (c) => {
    const results = await checkAll();
    return c.json({ up: results.every((r) => r.ok), results });
});

app.get('/', (c) => c.text('Uptime monitor: runs every 5 minutes. GET /status checks now.'));

export default app;
