# Cloud Functions

A **Function** is a service whose code you write in Kiln itself: no repository, no Dockerfile. Deploying takes a few
seconds, the function gets a URL like any other service, and it **scales to zero** when nobody calls it. On
traffic, it starts again and scales out.

A function runs on **Bun, Node.js, Deno or Python**, and can serve HTTP, run on schedules, or both.

## Create one

On the canvas: **Create → Function**.

1. Pick a **runtime**: Bun, Node.js, Deno or Python.
2. Pick a **starter**. Each one is ready to use, and the TypeScript ones are shared by Bun, Node and Deno.

   | Category | Starter |
   |---|---|
   | Basics | Hello API |
   | Data | JSON API + Postgres (notes CRUD) |
   | Webhooks | Signed webhook receiver (HMAC, GitHub style) · Stripe webhooks (signature check, checkout/invoice/subscription events) |
   | Bots | Telegram bot (commands, `/setup` registers the webhook) |
   | Notifications | Slack / Discord notifier (token-protected relay) |
   | Scheduled | Scheduled job (hourly) · Uptime monitor (every 5 minutes, alerts Slack/Discord, `GET /status`) |
   | APIs | Caching API proxy (hides the upstream key, CORS, GET cache) |
   | Forms | Contact form → email (Resend, spam honeypot) |

   A starter creates the **variables** it reads. Secrets it needs, such as webhook secrets and tokens, are
   generated; fill in the rest in Variables. Scheduled starters also create their **schedule**.
3. Pick a **server** and a **domain**, then **Create and deploy**. The starter becomes version 1 and is live a few
   seconds later.

The server needs Docker and a Kiln agent 0.4 or newer (it runs the *function gateway*).

### TypeScript (Bun, Node.js, Deno)

```ts
import { Hono } from 'hono'
import postgres from 'postgres'

const sql = postgres(process.env.DATABASE_URL!)
const app = new Hono()

app.get('/', (c) => c.json({ hello: 'world' }))
app.get('/users/:id', async (c) => c.json(await sql`select * from users where id = ${c.req.param('id')}`))

export default app                          // a Hono app, { fetch }, or a fetch(request) function
export async function scheduled(event) { }  // optional: runs on the function's schedules
```

- The entry file is `index.ts`. Packages you import are installed when you deploy, and their versions are pinned
  per code version:

  | Runtime | Installed with |
  |---|---|
  | Bun | `bun install` |
  | Node.js 24 | `npm`; Node runs TypeScript natively, so use erasable syntax only (no `enum`, no `namespace`) |
  | Deno 2 | `npm:` packages; it runs with network, env and read access to `/app` only |

- Write against Hono, the Web APIs (`fetch`, `crypto.subtle`, `Request`, `Response`) and npm packages that run
  everywhere, and the same file works on all three runtimes.

### Python

```python
# /// script
# dependencies = ["fastapi", "httpx"]
# ///
from fastapi import FastAPI

app = FastAPI()                 # any ASGI app: FastAPI, Starlette, …

@app.get("/hello/{name}")
def hello(name: str):
    return {"message": f"Hello, {name}!"}

async def scheduled(event):     # optional: runs on the function's schedules (def or async def)
    ...
```

- The entry file is `main.py`, served by uvicorn.
- Dependencies come from the `# /// script` block at the top (PEP 723) or a `requirements.txt`. They are installed
  with `uv` when you deploy and locked per code version.

## Edit and deploy

The **Code** tab is a full editor, with TypeScript autocomplete for Hono and Bun.

- **Drafts:** your edits autosave as *your* draft; teammates don't see them until you deploy.
- **Deploy** (⌘S): saves the code as a new version with an optional message, and deploys it.
- **Deploy history:** the deploy appears in the Deployments tab like any other service's deploy.
- **When the new version fails:** if it doesn't install or start, the deploy fails and the previous version keeps
  serving.
- **If a teammate deployed while you were editing:** Kiln shows their version next to yours. You can take theirs,
  keep editing, or deploy yours on top.

## Versions and rollback

Each version is immutable and records its author, message and a short hash (the hash is the deployment's commit).
In the **Versions** tab you can:

- **Compare** any version against the live one.
- **Deploy this version** to roll back. The server keeps recent releases installed, so a rollback is instant.
- **Restore to editor** to start a new change from an old version.

## Schedules (cron)

A function can also run on a schedule. Export a `scheduled` handler:

```ts
export async function scheduled(event) {
    // event = { name, schedule, cron, trigger: 'cron' | 'manual', scheduledTime }
    await sql`delete from sessions where expires_at < now()`
}

export default app // the HTTP side is optional for scheduled-only functions
```

`export default { fetch: app.fetch, scheduled }` works too. In Python, define `def scheduled(event)` or
`async def scheduled(event)` in `main.py`; `event` is a dict with the same fields (`scheduled_time` instead of
`scheduledTime`).

In the **Schedules** tab, add one or more schedules:

| Field | Options |
|---|---|
| When | a preset, a 5-field cron expression, `@hourly`…`@yearly`, or `@every 10m` |
| Timezone | any timezone |
| Timeout | the run is stopped after this many seconds |
| If still running | skip the next run (default), or run anyway |

Each run is a new container on the function's server, with the same release, variables, limits and isolation as
its HTTP instances. It doesn't touch the instances that serve traffic, so a function that sleeps stays asleep
between runs.

A run succeeds when the handler resolves, and fails when it throws.

**Run now** runs a schedule immediately and shows its output live.

**History:**
- Runs, failures, timeouts and missed runs appear under **Observability → Scheduled tasks**, like any scheduled
  job.
- Errors become **Issues**, with the stack trace.

The **Scheduled job** starter comes with an hourly schedule.

## Variables and databases

Functions use the **Variables** tab like every service, including references such as
`DATABASE_URL=${{ postgres.DATABASE_URL }}`. Bun's built-in `sql` client reads `DATABASE_URL`. Kiln sets `PORT`
itself, so don't define it.

## Scaling

**Settings → Scaling**:

| Setting | Default | What it does |
|---|---|---|
| Min instances | 0 | 0 = scale to zero; 1+ keeps instances warm (no cold starts) |
| Max instances | 5 | Upper bound under load |
| Concurrency | 50 | Requests per instance before another one starts |
| Idle timeout | 300 s | An instance with no requests this long is stopped |
| Memory / CPU | 256 MB / 0.5 | Per instance |
| Request timeout | 30 s | Longer requests get a 504 |

**How it works:** Caddy sends the function's traffic to `kiln-fn-gateway` on the server.

- **Nothing running:** the gateway holds the first request, starts an instance (its container already exists, so this
  is a `docker start`) and forwards the request when the runtime is ready. A Bun cold start takes about 0.2 s.
- **Busy:** when every instance is at its concurrency limit, the gateway starts another one, up to max instances.
- **Idle:** instances are stopped after the idle timeout, down to min instances.

The gateway runs as its own systemd service, so agent upgrades don't interrupt function traffic.

## Observability

Functions report to Kiln without any package.

**Observability tab** (every runtime)
- Requests, error rate and p95, with the slow routes listed by their route (`GET /users/:id` in Hono,
  `GET /hello/{name}` in FastAPI). Paths no route
  matches are grouped as `(unmatched)`.
- Issues from uncaught errors, with the stack trace.
- Outgoing calls: `fetch` in TypeScript, `httpx` and `requests` in Python. Query strings are not recorded.
- Cold starts are marked on the request that waited for one (`faas.coldstart`).
- Requests the gateway answers itself are listed as `(function unavailable)`: a release that fails to start, a start
  timeout, or a full queue.

**Code tab:** the live state, *Sleeping* or *N running*, with requests in flight, request and cold-start counts, and
the time of the last request.

**Logs tab:** `console.log` output.

**How it works:** each function reports on its own socket. The gateway stamps the function's identity on what
arrives there, so a function can't report as another one, and hands it to the agent.

Set `KILN_TELEMETRY=off` in Variables to turn the built-in tracing off.

## Isolation

Every instance runs with:

- a non-root user and a read-only root filesystem, with the code mounted read-only
- a small temporary `/tmp`
- no Linux capabilities and `no-new-privileges`
- memory, CPU and process limits
- no Docker socket

Instances live on their own Docker network (`kiln-fn`). They reach the internet and your databases' private
addresses.

## Limits

- 1 MB of code per version, 50 files. The editor shows one file for now; versions already store several.
- A function runs on one server; multi-server functions come with load balancing.
- Health checks don't apply: a health probe would keep the function awake.
