// kiln-fn-serve (Node): loads /app/$KILN_ENTRYPOINT (Node strips TypeScript types itself) and serves its default
// export (a Hono app, { fetch } or a fetch function) on 0.0.0.0:$PORT through a small node:http ↔ Fetch adapter.
// It listens only once the module has loaded: the gateway treats the first accepted connection as "ready".
// Telemetry first, so outgoing fetch calls of the function are traced.
import { flush, instrument } from "../shared/telemetry.mjs";
import { entry, fetchHandler, loadEntry } from "../shared/entry.mjs";
import { once } from "node:events";
import { createServer } from "node:http";
import { Readable } from "node:stream";

const port = Number(process.env.PORT ?? 8080);
const { fetch, app } = fetchHandler(await loadEntry());
const handle = instrument(fetch, app);

/** A Fetch Request for an incoming node:http request (the body streams; aborted when the client goes away). */
function toRequest(req, signal) {
  const headers = new Headers();
  for (let i = 0; i < req.rawHeaders.length; i += 2) headers.append(req.rawHeaders[i], req.rawHeaders[i + 1]);
  const method = req.method ?? "GET";
  const init = { method, headers, signal };
  if (method !== "GET" && method !== "HEAD") {
    init.body = Readable.toWeb(req);
    init.duplex = "half";
  }
  return new Request(`http://${req.headers.host ?? "localhost"}${req.url ?? "/"}`, init);
}

/** Writes a Fetch Response to node:http, streaming the body (back-pressure respected). */
async function send(res, response, method, signal) {
  const headers = {};
  response.headers.forEach((value, key) => {
    if (key !== "set-cookie") headers[key] = value;
  });
  const cookies = response.headers.getSetCookie();
  if (cookies.length > 0) headers["set-cookie"] = cookies;
  res.writeHead(response.status, response.statusText, headers);
  if (!response.body || method === "HEAD") {
    res.end();
    return;
  }
  const reader = response.body.getReader();
  try {
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      // A destroyed socket never emits "drain": stop on the client's disconnect too.
      if (!res.write(value)) await once(res, "drain", { signal });
    }
    res.end();
  } catch (err) {
    // Release the body's source (a proxied fetch, a cursor…) when the client went away.
    await reader.cancel(err).catch(() => {});
    if (!signal.aborted) throw err;
  }
}

const server = createServer(async (req, res) => {
  const aborted = new AbortController();
  res.on("close", () => {
    if (!res.writableFinished) aborted.abort();
  });
  try {
    // Like @hono/node-server, the raw objects are the handler's env (c.env.incoming / c.env.outgoing).
    const response = await handle(toRequest(req, aborted.signal), { incoming: req, outgoing: res });
    if (!response) throw new Error("the handler returned no Response");
    await send(res, response, req.method, aborted.signal);
  } catch (err) {
    if (aborted.signal.aborted) return;
    console.error(err);
    if (!res.headersSent) res.writeHead(500, { "content-type": "text/plain; charset=utf-8" });
    res.end("Internal Server Error");
  }
});
server.keepAliveTimeout = 60_000;
server.listen(port, "0.0.0.0", () => console.log(`kiln: ${entry} listening on :${port}`));

for (const sig of ["SIGTERM", "SIGINT"]) {
  process.on(sig, async () => {
    server.close();
    server.closeIdleConnections();
    await flush();
    process.exit(0);
  });
}
