// falak-fn-serve (Deno): loads /app/$FALAK_ENTRYPOINT and serves its default export (a Hono app, { fetch } or a fetch
// function) with Deno.serve on 0.0.0.0:$PORT, once the module has loaded (the gateway treats the first accepted
// connection as "ready"). falak-fn-serve runs it with --cached-only and the permissions in README.md.
// Telemetry first, so outgoing fetch calls of the function are traced.
import { flush, instrument } from "../shared/telemetry.mjs";
import { entry, fetchHandler, loadEntry } from "../shared/entry.mjs";

const port = Number(Deno.env.get("PORT") ?? 8080);
const { fetch, app } = fetchHandler(await loadEntry());
const handle = instrument(fetch, app);

const server = Deno.serve(
  { hostname: "0.0.0.0", port, onListen: () => console.log(`falak: ${entry} listening on :${port}`) },
  async (req, info) => (await handle(req, info)) ?? new Response("Internal Server Error", { status: 500 }),
);

for (const sig of ["SIGTERM", "SIGINT"]) {
  Deno.addSignalListener(sig, async () => {
    await server.shutdown();
    await flush();
    Deno.exit(0);
  });
}
