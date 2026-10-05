import { Hono } from 'hono';

// Falak E2E demo (TypeScript on Bun): proves native TS builds and reverse-proxied runtimes.
const app = new Hono();

app.get('/', (c) =>
    c.json({
        app: 'falak-bun-demo',
        release: process.env.FALAK_RELEASE_ID ?? null,
        deployment: process.env.FALAK_DEPLOYMENT_ID ?? null,
        greeting: process.env.FALAK_E2E_GREETING ?? null,
        server: process.env.HOSTNAME ?? null,
    }),
);

app.get('/health', (c) => c.text('ok'));

app.get('/boom', () => {
    throw new Error('Falak E2E bun demo exception');
});

const port = Number(process.env.PORT ?? 3000);

export default { port, fetch: app.fetch };
