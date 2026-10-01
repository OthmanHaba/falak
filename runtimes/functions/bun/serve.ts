// kiln-fn-serve (Bun): loads /app/$KILN_ENTRYPOINT and serves its default export on 0.0.0.0:$PORT.
//
// The default export may be a Hono app (or anything with fetch(request)), a Bun-style { fetch, websocket }
// object, or a plain fetch handler function. The gateway treats the first accepted connection as "ready", so the
// server only listens once the module has loaded.
// Telemetry first, so outgoing fetch calls of the function are traced.
import { flush, instrument } from "../shared/telemetry.mjs";
import { entry, fetchHandler, loadEntry } from "../shared/entry.mjs";

const port = Number(process.env.PORT ?? 8080);
const { fetch, app, websocket } = fetchHandler(await loadEntry());

const server = Bun.serve({
  hostname: "0.0.0.0",
  port,
  idleTimeout: 60,
  fetch: instrument(fetch, app),
  websocket: websocket as never,
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
