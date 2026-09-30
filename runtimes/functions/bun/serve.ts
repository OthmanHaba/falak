// kiln-fn-serve: loads /app/$KILN_ENTRYPOINT and serves its default export on 0.0.0.0:$PORT.
//
// The default export may be a Hono app (or anything with fetch(request)), a Bun-style { fetch, websocket }
// object, or a plain fetch handler function. The gateway treats the first accepted connection as "ready", so the
// server only listens once the module has loaded.
import { join } from "node:path";
// First, so outgoing fetch calls of the function are traced.
import { flush, instrument } from "./telemetry.ts";

const entry = process.env.KILN_ENTRYPOINT ?? "index.ts";
const port = Number(process.env.PORT ?? 8080);

let mod: Record<string, unknown>;
try {
  mod = await import(join(process.cwd(), entry));
} catch (err) {
  console.error(`kiln: failed to load ${entry}:`, err);
  process.exit(1);
}

type Handler = (req: Request, server: unknown) => Response | Promise<Response>;
const def = mod.default as { fetch?: Handler; websocket?: unknown } | Handler | undefined;
let fetch: Handler;
if (def && typeof (def as { fetch?: Handler }).fetch === "function") {
  const obj = def as { fetch: Handler };
  fetch = obj.fetch.bind(obj);
} else if (typeof def === "function") {
  fetch = def as Handler;
} else {
  console.error(
    `kiln: ${entry} must \`export default\` a Hono app, an object with fetch(request), or a fetch(request) function`,
  );
  process.exit(1);
}

fetch = instrument(fetch, def) as Handler;

const server = Bun.serve({
  hostname: "0.0.0.0",
  port,
  idleTimeout: 60,
  fetch,
  websocket: (def as { websocket?: never })?.websocket,
  error(err) {
    console.error(err);
    return new Response("Internal Server Error", { status: 500 });
  },
});
console.log(`kiln: ${entry} listening on :${server.port}`);

for (const sig of ["SIGTERM", "SIGINT"] as const) {
  process.on(sig, async () => {
    await server.stop();
    await flush();
    process.exit(0);
  });
}
