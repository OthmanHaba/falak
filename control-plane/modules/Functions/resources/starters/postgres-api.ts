import { Hono } from 'hono'
import { sql } from 'bun'

// Bun's built-in Postgres client reads DATABASE_URL. Add it in Variables, e.g. ${{ postgres.DATABASE_URL }}
// (connect a database on the canvas to get the reference).
const app = new Hono()

app.get('/', async (c) => {
    const [row] = await sql`select now() as now, version() as version`
    return c.json(row)
})

app.get('/notes', async (c) => {
    await sql`create table if not exists notes (id serial primary key, body text not null, created_at timestamptz default now())`
    return c.json(await sql`select * from notes order by id desc limit 50`)
})

app.post('/notes', async (c) => {
    const { body } = await c.req.json<{ body: string }>()
    if (!body) return c.json({ error: 'body is required' }, 422)
    const [note] = await sql`insert into notes (body) values (${body}) returning *`
    return c.json(note, 201)
})

export default app
