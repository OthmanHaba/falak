import { Hono } from 'hono';
import { cors } from 'hono/cors';

// A caching proxy in front of another API: hides its key, adds CORS and caches GET responses.
// Variables:
//   UPSTREAM_URL            e.g. https://api.example.com
//   UPSTREAM_AUTHORIZATION  sent upstream as the Authorization header (optional, never exposed to browsers)
//   CACHE_SECONDS           how long GET responses are cached in memory (default 60)
//   ALLOWED_ORIGIN          CORS origin (default *)
// The cache lives in the instance's memory: it is per instance and empty after the function scales to zero.
const app = new Hono();
const cache = new Map<string, { expires: number; status: number; body: ArrayBuffer; type: string }>();
const ttl = () => Number(process.env.CACHE_SECONDS ?? 60) * 1000;

app.use('*', cors({ origin: process.env.ALLOWED_ORIGIN || '*' }));

app.all('/*', async (c) => {
    const upstream = process.env.UPSTREAM_URL;
    if (!upstream) return c.json({ error: 'UPSTREAM_URL is not set' }, 500);

    const incoming = new URL(c.req.url);
    // Appended to the configured base (keeps its path; `//other.host` in the request cannot change the host).
    const target = new URL(upstream.replace(/\/+$/, '') + incoming.pathname + incoming.search);
    if (target.origin !== new URL(upstream).origin) return c.json({ error: 'bad path' }, 400);
    const key = target.toString();

    if (c.req.method === 'GET') {
        const hit = cache.get(key);
        if (hit && hit.expires > Date.now()) {
            return new Response(hit.body, { status: hit.status, headers: { 'Content-Type': hit.type, 'X-Cache': 'HIT' } });
        }
    }

    const headers: Record<string, string> = { Accept: c.req.header('Accept') ?? '*/*' };
    if (c.req.header('Content-Type')) headers['Content-Type'] = c.req.header('Content-Type')!;
    if (process.env.UPSTREAM_AUTHORIZATION) headers.Authorization = process.env.UPSTREAM_AUTHORIZATION;

    const res = await fetch(target, {
        method: c.req.method,
        headers,
        body: ['GET', 'HEAD'].includes(c.req.method) ? undefined : await c.req.arrayBuffer(),
    });
    const body = await res.arrayBuffer();
    const type = res.headers.get('Content-Type') ?? 'application/octet-stream';

    if (c.req.method === 'GET' && res.ok) {
        cache.set(key, { expires: Date.now() + ttl(), status: res.status, body, type });
        if (cache.size > 1000) cache.delete(cache.keys().next().value!);
    }

    return new Response(body, { status: res.status, headers: { 'Content-Type': type, 'X-Cache': 'MISS' } });
});

export default app;
