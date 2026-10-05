# Falak function runtime: Deno

Deno 2 (`denoland/deno`, Debian) for Cloud Functions. It follows the runtime convention in `../bun/README.md`
(`falak-fn-install`, `falak-fn-serve`, `falak-fn-run`; same mounts, environment, isolation and telemetry as Bun).

```ts
import { Hono } from 'hono'       // or 'npm:hono', 'jsr:@hono/hono'

const app = new Hono()
app.get('/', (c) => c.json({ hello: 'world' }))

export async function scheduled(event) {} // optional: runs on the function's schedules

export default app            // a Hono app, { fetch }, or a fetch(request) function
```

## Dependencies

Everything is resolved at install time, into the release, so serving needs no network and no writable cache
(the release is mounted read-only):

- **No `deno.json`**: `falak-fn-install` writes one. Without a `package.json`, the imports of the source files
  (`../shared/scan.mjs`) become `"<pkg>": "npm:<pkg>"` entries, so bare imports like `hono` work as on Bun and
  Node. With a `package.json`, its dependencies are used.
- **Your own `deno.json`**: kept. Falak only adds `"nodeModulesDir": "auto"` (npm packages in `/app/node_modules`) and
  `"vendor": true` (`jsr:` and `https:` modules in `/app/vendor`) when they are missing. A `deno.jsonc` is used as
  is and needs both settings itself.
- `deno install --entrypoint <entry>` writes `deno.lock`. The agent keeps it (and the generated `deno.json`) per code
  version, so the same code always installs the same versions. Downloads are cached in `/cache/deno`.

## Serving and permissions

`falak-fn-serve` and `falak-fn-run` run with `--cached-only` (no module downloads), a scratch `DENO_DIR` in `/tmp`,
and these permissions:

```
--allow-net --allow-env --allow-sys
--allow-read=/app,/tmp,/run/falak-otlp --allow-write=/tmp,/run/falak-otlp
```

`--allow-sys` (hostname, OS and user info) is there because common npm packages, database drivers among them, read
it. The function has no subprocesses, FFI or access to the rest of the filesystem.

Telemetry uses the shared tracer (`../shared/telemetry.mjs`); Deno's `fetch` cannot use a unix socket, so batches go
over `Deno.connect` with a minimal HTTP/1.1 request.

```sh
docker build -t falak-fn-deno:dev -f runtimes/functions/deno/Dockerfile runtimes/functions
```
