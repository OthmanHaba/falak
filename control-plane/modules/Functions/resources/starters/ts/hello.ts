import { Hono } from 'hono';
import { logger } from 'hono/logger';

const app = new Hono();

app.use(logger());

app.get('/', (c) => c.json({ message: 'Hello from Falak!', time: new Date().toISOString() }));

app.get('/hello/:name', (c) => c.text(`Hello, ${c.req.param('name')}!`));

export default app;
