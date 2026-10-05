# Falak function runtime: Bun

The image Falak runs Cloud Functions with (`fn.release.apply` → `falak-fn-gateway`). Based on `oven/bun:<version>-slim`.

```ts
import { Hono } from 'hono'

const app = new Hono()
app.get('/', (c) => c.json({ hello: 'world' }))

export default app            // a Hono app, { fetch, websocket }, or a fetch(request) function
```

## Runtime convention

Every function runtime image (Bun, Node, Deno, Python, Go) ships these commands. The agent and the gateway
only rely on these, never on the language.

| Command | Runs | Mounts | Must |
|---|---|---|---|
| `falak-fn-install` | once per release, in a one-shot container, with network | `/app` = release dir (read-write, owned by 65534), `/cache` = the server's package cache for this runtime | resolve dependencies into `/app`; exit 0 on success. Output is streamed to the deployment log |
| `falak-fn-serve` | per instance, started and stopped by the gateway | `/app` read-only | load `/app/$FALAK_ENTRYPOINT` and listen on `0.0.0.0:$PORT` (8080) once loaded. An accepted TCP connection means ready |
| `falak-fn-run` | once per scheduled run (or Run now), in a one-shot container like an instance's | `/app` read-only | call the function's scheduled handler; exit 0 on success, non-zero on failure. Env adds `FALAK_TRIGGER` (`cron`/`manual`), `FALAK_SCHEDULE`, `FALAK_SCHEDULE_NAME`, `FALAK_SCHEDULE_CRON` |

Both run as uid/gid `65534`, with a read-only root filesystem, a tmpfs `/tmp` (`HOME=/tmp`), no capabilities,
`no-new-privileges`, a pids limit, memory and CPU limits, and only the `falak-fn` bridge network. Nothing may be
written outside `/tmp` (and `/app`, `/cache` during install).

Environment: `FALAK_ENTRYPOINT`, `PORT`, plus the function's variables.

## Telemetry

`falak-fn-serve` should report to `$FALAK_OTLP_SOCKET` (OTLP/HTTP, JSON or protobuf, on a unix socket mounted
read-only at `/run/falak-otlp`). The gateway replaces the resource's `service.name` and `falak.*` attributes with the
function's own identity and relays everything to the agent (Insights, traces). Spans follow the Falak telemetry
contract (`contracts/telemetry/README.md`). The gateway adds `X-Falak-Cold-Start: 1` to a request that waited for an
instance to start; report it as `faas.coldstart`.

The JS runtimes (Bun, Node, Deno) do this without any package, with the same tracer (`../shared/telemetry.mjs`;
the entry loader and the scheduled runner are shared too):
- a `request` span per request, named by the Hono route template (`GET /users/:id`); paths no route matches are
  grouped as `(unmatched)`, and plain fetch handlers get `/users/:id`-style names from the path;
- exceptions: errors Hono's error handler turns into a 500, and errors thrown by a plain handler, with the stack;
- `outgoing_request` spans for `fetch` calls made while handling a request: no query strings or userinfo, and
  secret-looking path segments redacted (`shared/redact.mjs`; the Python and Go runtimes and the gateway use the
  same rules and the same test cases, `../tests/redact-cases.json`).

Set `FALAK_TELEMETRY=off` in the function's variables to turn it off.

## Dependencies (Bun)

Without a `package.json`, `falak-fn-install` reads the imports of every source file (`Bun.Transpiler.scanImports`):
each bare specifier that is not a Node or Bun builtin becomes a dependency (`hono/cors` → `hono`,
`@scope/pkg/x` → `@scope/pkg`) at its latest version. `bun install` writes `bun.lock` into the release, so the
release (and a rollback to it) keeps exactly those versions. A `package.json` among the function's files is used
as is.

## Build and try

```sh
docker build -t falak-fn-bun:dev -f runtimes/functions/bun/Dockerfile runtimes/functions   # context: shared/ too
mkdir -p /tmp/fn/app /tmp/fn/cache && cp index.ts /tmp/fn/app/
docker run --rm --read-only --tmpfs /tmp --user 65534:65534 -e FALAK_ENTRYPOINT=index.ts \
  -v /tmp/fn/app:/app -v /tmp/fn/cache:/cache falak-fn-bun:dev falak-fn-install
docker run --rm --read-only --tmpfs /tmp --user 65534:65534 -e FALAK_ENTRYPOINT=index.ts \
  -v /tmp/fn/app:/app:ro -p 127.0.0.1:8080:8080 falak-fn-bun:dev
```
