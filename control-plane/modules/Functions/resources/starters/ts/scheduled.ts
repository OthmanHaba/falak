import { Hono } from 'hono';

// Runs on its schedules (Schedules tab) in a one-shot container, and serves HTTP like any function.
// The event says which schedule fired: { name, schedule, cron, trigger: 'cron' | 'manual', scheduledTime }.
export async function scheduled(event: { name: string; trigger: string; scheduledTime: number }) {
    const started = new Date(event.scheduledTime).toISOString();
    console.log(`${event.name} (${event.trigger}) started at ${started}`);

    // Do the work here: clean up rows, send a report, sync an API…
    const res = await fetch('https://api.github.com/zen');
    console.log('zen:', await res.text());
}

const app = new Hono();

app.get('/', (c) => c.json({ ok: true, hint: 'This function also runs on a schedule: see its Schedules tab.' }));

export default app;
