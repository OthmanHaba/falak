// Built-in telemetry of Falak functions, shared by the Bun, Node and Deno runtimes: request spans (named by the Hono
// route), exceptions, outgoing fetch calls and scheduled runs, following the Falak telemetry contract
// (contracts/telemetry/README.md). No dependencies, so cold starts stay fast: Web APIs and node:async_hooks, which
// all three runtimes have. Spans are batched and sent as OTLP/HTTP JSON to the function's socket
// ($FALAK_OTLP_SOCKET), which the gateway relays to the agent under the function's identity. Telemetry never blocks
// or fails a request.
import { AsyncLocalStorage } from "node:async_hooks";
import { existsSync } from "node:fs";
import { redactPath, redactText, safeUrl } from "./redact.mjs";

/** @typedef {{ key: string, value: { stringValue?: string, intValue?: string, boolValue?: boolean } }} Attr */
/** @typedef {{ name: string, timeUnixNano: string, attributes: Attr[] }} SpanEvent */
/**
 * @typedef {object} Span
 * @property {string} traceId
 * @property {string} spanId
 * @property {string} [parentSpanId]
 * @property {string} name
 * @property {number} kind
 * @property {string} startTimeUnixNano
 * @property {string} [endTimeUnixNano]
 * @property {Attr[]} attributes
 * @property {SpanEvent[]} events
 * @property {{ code: number, message?: string }} [status]
 */
/** @typedef {(req: Request, server?: unknown) => Response | undefined | Promise<Response | undefined>} Handler */

const INTERNAL = 1;
const SERVER = 2;
const CLIENT = 3;
const ERROR = 2;
const FLUSH_MS = 1000;
const MAX_BATCH = 256;
const MAX_QUEUE = 4096;
const SEND_TIMEOUT_MS = 2000;
const UNMATCHED = "(unmatched)";

const env = globalThis.process?.env ?? {};
const socket = env.FALAK_OTLP_SOCKET ?? "";
const enabled = socket !== "" && env.FALAK_TELEMETRY !== "off" && safeExists(socket);
const originalFetch = globalThis.fetch.bind(globalThis);
/** @type {AsyncLocalStorage<Span>} */
const current = new AsyncLocalStorage();
/** @type {Span[]} */
let queue = [];
/** @type {ReturnType<typeof setTimeout> | undefined} */
let timer;
/** @type {Promise<void> | undefined} */
let flushing;

const runtime = globalThis.Bun ? "bun" : globalThis.Deno ? "deno" : "node";
/** Posts one OTLP/HTTP JSON body to /v1/traces on the socket; rejects when it cannot be delivered. */
let transport = defaultTransport();

/**
 * Overrides how batches reach the socket (tests, other runtimes).
 *
 * @param {{ send?: (body: string) => Promise<void> }} options
 */
export function configure(options) {
  if (options.send) transport = options.send;
}

function safeExists(path) {
  try {
    return existsSync(path);
  } catch {
    return false; // no permission to look (Deno without --allow-read for it)
  }
}

const hex = (bytes) => Array.from(crypto.getRandomValues(new Uint8Array(bytes)), (x) => x.toString(16).padStart(2, "0")).join("");
const nanos = () => (BigInt(Math.round(performance.timeOrigin * 1000 + performance.now() * 1000)) * 1000n).toString();
/** @returns {Attr} */
const str = (key, v) => ({ key, value: { stringValue: v } });
/** @returns {Attr} */
const int = (key, v) => ({ key, value: { intValue: String(Math.trunc(v)) } });
/** @returns {Attr} */
const bool = (key, v) => ({ key, value: { boolValue: v } });

function schedule() {
  if (timer === undefined) timer = setTimeout(() => void flush(), FLUSH_MS);
}

/** @param {Span} span */
function finish(span) {
  if (!enabled) return;
  span.endTimeUnixNano = nanos();
  if (queue.length >= MAX_QUEUE) return; // agent down: drop rather than grow
  queue.push(span);
  if (queue.length >= MAX_BATCH) void flush();
  else schedule();
}

/**
 * Sends everything queued (also called on shutdown); one flush at a time.
 *
 * @returns {Promise<void>}
 */
export function flush() {
  if (timer !== undefined) {
    clearTimeout(timer);
    timer = undefined;
  }
  flushing ??= send().finally(() => {
    flushing = undefined;
    if (queue.length > 0) schedule();
  });
  return flushing;
}

async function send() {
  while (queue.length > 0) {
    const batch = queue.splice(0, MAX_BATCH);
    const body = JSON.stringify({
      resourceSpans: [
        {
          resource: { attributes: [str("telemetry.sdk.name", "falak-fn"), str("telemetry.sdk.language", "js"), str("process.runtime.name", runtime)] },
          scopeSpans: [{ scope: { name: `falak-fn-${runtime}` }, spans: batch }],
        },
      ],
    });
    try {
      await transport(body);
    } catch {
      // Telemetry is best effort: the socket is down, drop what is queued rather than retry (a shutdown must
      // not outlast the stop grace period).
      queue = [];
      return;
    }
  }
}

/** The runtime's own way of posting over a unix socket. */
function defaultTransport() {
  if (runtime === "bun") {
    return async (body) => {
      const res = await originalFetch("http://falak/v1/traces", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body,
        unix: socket,
        signal: AbortSignal.timeout(SEND_TIMEOUT_MS),
      });
      await res.arrayBuffer();
      if (!res.ok) throw new Error(`status ${res.status}`);
    };
  }
  if (runtime === "deno") return denoSend;
  return nodeSend;
}

/** Node: node:http over the socket. */
async function nodeSend(body) {
  const { request } = await import("node:http");
  const bytes = Buffer.from(body);
  await new Promise((resolve, reject) => {
    const req = request(
      { socketPath: socket, path: "/v1/traces", method: "POST", timeout: SEND_TIMEOUT_MS, headers: { "Content-Type": "application/json", "Content-Length": bytes.length } },
      (res) => {
        res.resume();
        res.on("end", () => ((res.statusCode ?? 500) < 300 ? resolve(undefined) : reject(new Error(`status ${res.statusCode}`))));
      },
    );
    req.on("timeout", () => req.destroy(new Error("timeout")));
    req.on("error", reject);
    req.end(bytes);
  });
}

/** Deno: fetch cannot use a unix socket, so a minimal HTTP/1.1 request over Deno.connect. */
async function denoSend(body) {
  const payload = new TextEncoder().encode(body);
  const head = new TextEncoder().encode(
    `POST /v1/traces HTTP/1.1\r\nHost: falak\r\nContent-Type: application/json\r\nContent-Length: ${payload.length}\r\nConnection: close\r\n\r\n`,
  );
  const conn = await globalThis.Deno.connect({ transport: "unix", path: socket });
  const timeout = setTimeout(() => conn.close(), SEND_TIMEOUT_MS);
  try {
    for (const chunk of [head, payload]) {
      for (let off = 0; off < chunk.length; ) off += await conn.write(chunk.subarray(off));
    }
    const buf = new Uint8Array(64);
    const n = await conn.read(buf);
    const status = Number(/^HTTP\/1\.[01] (\d{3})/.exec(new TextDecoder().decode(buf.subarray(0, n ?? 0)))?.[1] ?? 0);
    if (status < 200 || status >= 300) throw new Error(`status ${status}`);
  } finally {
    clearTimeout(timeout);
    try {
      conn.close();
    } catch {
      // already closed by the timeout
    }
  }
}

/** @returns {SpanEvent} */
function exceptionEvent(err, handled) {
  const e = err instanceof Error ? err : new Error(String(err));
  return {
    name: "exception",
    timeUnixNano: nanos(),
    attributes: [
      str("exception.type", e.name || "Error"),
      str("exception.message", redactText(e.message).slice(0, 4096)),
      str("exception.stacktrace", redactText(e.stack ?? "").slice(0, 16384)),
      bool("falak.exception.handled", handled),
    ],
  };
}

const message = (err) => redactText(err instanceof Error ? err.message : String(err)).slice(0, 512);

/**
 * Records an exception on the current request (handled = your code caught it).
 *
 * @param {unknown} err
 * @param {boolean} [handled]
 */
export function recordException(err, handled = false) {
  const span = current.getStore();
  if (!span) return;
  span.events.push(exceptionEvent(err, handled));
  if (!handled) span.status = { code: ERROR, message: message(err) };
}

// /users/42 → /users/:id (numbers, UUIDs, long hex or base64-ish segments), for handlers without a router.
function normalize(path) {
  return (
    path
      .split("/")
      .map((seg) => (/^\d+$|^[0-9a-f-]{16,}$|^[A-Za-z0-9_-]{24,}$/i.test(seg) ? ":id" : seg))
      .join("/") || "/"
  );
}

/** The Hono route template a request matches (`/users/:id`), from the app's own router. */
function honoRoute(app, method, path) {
  try {
    const matches = app?.router?.match?.(method === "HEAD" ? "GET" : method, path)?.[0] ?? [];
    let route;
    for (const m of matches) {
      const r = m?.[0]?.[1];
      if (r?.path && r.method !== "ALL") route = r.path;
    }
    if (route === undefined && matches.length > 0) {
      const r = matches[matches.length - 1]?.[0]?.[1];
      if (r?.path && r.path !== "*" && r.path !== "/*") route = r.path;
    }
    return route;
  } catch {
    return undefined;
  }
}

/**
 * Wraps the function's fetch handler with a `request` span.
 *
 * @param {Handler} handler
 * @param {unknown} app the default export (a Hono app gives route names and its error handler)
 * @returns {Handler}
 */
export function instrument(handler, app) {
  if (!enabled) return handler;
  const hono = /** @type {any} */ (app);
  // Hono turns thrown errors into a 500 in its error handler: record them there (still the request's context).
  if (typeof hono?.errorHandler === "function") {
    const original = hono.errorHandler.bind(hono);
    hono.errorHandler = (err, c) => {
      recordException(err, false);
      return original(err, c);
    };
  }
  return (req, server) => {
    const url = new URL(req.url);
    const parent = /^00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/.exec(req.headers.get("traceparent") ?? "");
    // Paths no Hono route matches (scanners, typos) share one name instead of flooding the route list.
    const route = honoRoute(hono, req.method, url.pathname) ?? (typeof hono?.router?.match === "function" ? UNMATCHED : redactPath(normalize(url.pathname)));
    const cold = req.headers.get("x-falak-cold-start") === "1";
    /** @type {Span} */
    const span = {
      traceId: parent?.[1] ?? hex(16),
      spanId: hex(8),
      parentSpanId: parent?.[2],
      name: `${req.method} ${route}`,
      kind: SERVER,
      startTimeUnixNano: nanos(),
      attributes: [
        str("falak.event.type", "request"),
        str("http.request.method", req.method),
        str("http.route", route),
        str("url.path", redactPath(url.pathname)),
        bool("faas.coldstart", cold),
      ],
      events: [],
    };
    const done = (status) => {
      span.attributes.push(int("http.response.status_code", status));
      if (status >= 500 && !span.status) span.status = { code: ERROR };
      finish(span);
    };
    return current.run(span, async () => {
      try {
        const res = await handler(req, server);
        done(res?.status ?? 101); // no response: a websocket upgrade
        return res;
      } catch (err) {
        span.events.push(exceptionEvent(err, false));
        span.status = { code: ERROR, message: message(err) };
        done(500);
        throw err;
      }
    });
  };
}

// Outgoing fetch → `outgoing_request` spans under the current request or run (no query strings or userinfo; secret
// path segments redacted, see redact.mjs).
if (enabled) {
  const wrapped = async (input, init) => {
    const parent = current.getStore();
    const req = input instanceof Request ? input : undefined;
    let url;
    try {
      url = new URL(req ? req.url : String(input));
    } catch {
      return originalFetch(input, init);
    }
    if (!parent || init?.unix) return originalFetch(input, init);
    const method = (init?.method ?? req?.method ?? "GET").toUpperCase();
    /** @type {Span} */
    const span = {
      traceId: parent.traceId,
      spanId: hex(8),
      parentSpanId: parent.spanId,
      name: `${method} ${url.host}`,
      kind: CLIENT,
      startTimeUnixNano: nanos(),
      attributes: [
        str("falak.event.type", "outgoing_request"),
        str("http.request.method", method),
        str("url.full", safeUrl(url)),
        str("server.address", url.hostname),
      ],
      events: [],
    };
    try {
      const res = await originalFetch(input, init);
      span.attributes.push(int("http.response.status_code", res.status));
      if (res.status >= 500) span.status = { code: ERROR };
      return res;
    } catch (err) {
      span.events.push(exceptionEvent(err, true));
      span.status = { code: ERROR, message: message(err) };
      throw err;
    } finally {
      finish(span);
    }
  };
  if (originalFetch.preconnect) wrapped.preconnect = originalFetch.preconnect;
  globalThis.fetch = wrapped;
}

/**
 * Runs a scheduled invocation as a `scheduled_task` span (status finished / failed, exceptions with stacks).
 *
 * @template T
 * @param {string} name
 * @param {string} expression
 * @param {() => Promise<T>} run
 * @returns {Promise<T>}
 */
export async function traceScheduled(name, expression, run) {
  /** @type {Span} */
  const span = {
    traceId: hex(16),
    spanId: hex(8),
    name: `schedule ${name}`,
    kind: INTERNAL,
    startTimeUnixNano: nanos(),
    attributes: [str("falak.event.type", "scheduled_task"), str("falak.schedule.name", name), str("falak.schedule.expression", expression)],
    events: [],
  };
  return current.run(span, async () => {
    try {
      const result = await run();
      span.attributes.push(str("falak.schedule.status", "finished"));
      return result;
    } catch (err) {
      span.attributes.push(str("falak.schedule.status", "failed"));
      span.events.push(exceptionEvent(err, false));
      span.status = { code: ERROR, message: message(err) };
      throw err;
    } finally {
      finish(span);
    }
  });
}
