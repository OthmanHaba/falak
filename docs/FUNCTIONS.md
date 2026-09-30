# Cloud Functions

A **Function** is a service whose code you write in Kiln itself: no repository, no Dockerfile. Deploying takes a few
seconds, the function gets a URL like any other service, and it **scales to zero** when nobody calls it. On
traffic, it starts again and scales out.

The first runtime is **Bun + Hono**. Node, Deno, Python and Go come next (see `docs/plans/FUNCTIONS.md`).

## Create one

On the canvas: **Create → Function**.

1. Pick a name, a server and a starter:
   - **Hello Hono**
   - **JSON API + Postgres**
   - **Webhook receiver**
2. Choose a domain: a generated name, your own domain, or a name in a Cloudflare zone Kiln manages.
3. **Create and deploy**. The starter becomes version 1 and is live a few seconds later.

The server needs Docker and a Kiln agent 0.4 or newer (it runs the *function gateway*).

```ts
import { Hono } from 'hono'
import { sql } from 'bun'

const app = new Hono()

app.get('/', (c) => c.json({ hello: 'world' }))
app.get('/users', async (c) => c.json(await sql`select id, name from users limit 20`))

export default app
```

Export a Hono app (or any `{ fetch }` handler) as the default export. Packages you import (`hono`, `zod`, …) are
installed on the server when you deploy, and pinned in that release's lockfile.

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

**Observability tab**
- Requests, error rate and p95, with the slow routes listed by their Hono route (`GET /users/:id`). Paths no route
  matches are grouped as `(unmatched)`.
- Issues from uncaught errors, with the stack trace.
- Outgoing `fetch` calls. Query strings are not recorded.
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
