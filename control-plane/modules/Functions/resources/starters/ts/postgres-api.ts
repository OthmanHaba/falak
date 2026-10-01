import { Hono } from 'hono';
import postgres from 'postgres';

// A small notes API on Postgres. Set DATABASE_URL in Variables, e.g. ${{ postgres.DATABASE_URL }}
// (connect a database on the canvas to get the reference).
const sql = postgres(process.env.DATABASE_URL ?? '', { max: 5, idle_timeout: 20 });

// Created on first use (not at load time, so the function deploys before DATABASE_URL is set).
let ready: Promise<unknown> | undefined;
const table = () =>
    (ready ??= sql`create table if not exists notes (id serial primary key, body text not null, created_at timestamptz default now())`);

const app = new Hono();

app.use('/notes/*', async (_c, next) => {
    await table();
    await next();
});
app.use('/notes', async (_c, next) => {
    await table();
    await next();
});

app.get('/', async (c) => {
    const [row] = await sql`select now() as now, version() as version`;
    return c.json(row);
});

app.get('/notes', async (c) => c.json(await sql`select * from notes order by id desc limit 50`));

app.get('/notes/:id', async (c) => {
    const [note] = await sql`select * from notes where id = ${Number(c.req.param('id'))}`;
    return note ? c.json(note) : c.json({ error: 'not found' }, 404);
});

app.post('/notes', async (c) => {
    const input = await c.req.json<{ body?: string }>().catch(() => null);
    if (!input?.body) return c.json({ error: 'send {"body": "…"}' }, 422);
    const [note] = await sql`insert into notes (body) values (${input.body}) returning *`;
    return c.json(note, 201);
});

app.delete('/notes/:id', async (c) => {
    const rows = await sql`delete from notes where id = ${Number(c.req.param('id'))} returning id`;
    return rows.length ? c.body(null, 204) : c.json({ error: 'not found' }, 404);
});

export default app;
