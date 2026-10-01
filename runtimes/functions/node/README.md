# Kiln function runtime: Node.js

Node.js 24 (`node:24-slim`) for Cloud Functions. It follows the runtime convention in `../bun/README.md`
(`kiln-fn-install`, `kiln-fn-serve`, `kiln-fn-run`; same mounts, environment, isolation and telemetry as Bun).

```ts
import { Hono } from 'hono'

const app = new Hono()
app.get('/', (c) => c.json({ hello: 'world' }))

export async function scheduled(event) {} // optional: runs on the function's schedules

export default app            // a Hono app, { fetch }, or a fetch(request) function
```

- **TypeScript**: `index.ts` runs as is. Node strips the types (type stripping), so use erasable syntax: no
  `enum`, `namespace` or parameter properties, and import local files with their extension (`./lib.ts`).
- **Serving**: a small `node:http` ↔ Fetch adapter (`serve.mjs`) passes a standard `Request` to the default export
  and streams the `Response` back. Like `@hono/node-server`, the raw request and response are the handler's env
  (`c.env.incoming`, `c.env.outgoing`). WebSockets are not supported on Node; use Bun or Deno for them.
- **Dependencies**: without a `package.json`, `kiln-fn-install` reads the imports of every source file
  (`../shared/scan.mjs`) and writes one with each npm package at its latest version, then runs `npm install`.
  `package-lock.json` stays in the release; the agent keeps it per code version, and a later install of the same
  code runs `npm ci` with it. A `package.json` among the function's files is used as is. The npm cache is `/cache/npm`.
- **Telemetry**: the shared tracer (`../shared/telemetry.mjs`) posts over the socket with `node:http`. Outgoing
  calls made with `fetch` are traced; other HTTP clients are not.

```sh
docker build -t kiln-fn-node:dev -f runtimes/functions/node/Dockerfile runtimes/functions
```
