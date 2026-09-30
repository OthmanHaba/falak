// Built-in telemetry of Kiln functions: request spans (named by the Hono route), exceptions and outgoing fetch
// calls, following the Kiln telemetry contract (contracts/telemetry/README.md). No dependencies, so cold starts
// stay fast. Spans are batched and sent as OTLP/HTTP JSON to the function's socket ($KILN_OTLP_SOCKET), which the
// gateway relays to the agent under the function's identity. Telemetry never blocks or fails a request.
import { AsyncLocalStorage } from "node:async_hooks";
import { existsSync } from "node:fs";

type Attr = { key: string; value: { stringValue?: string; intValue?: string; boolValue?: boolean } };
type SpanEvent = { name: string; timeUnixNano: string; attributes: Attr[] };
interface Span {
  traceId: string;
  spanId: string;
  parentSpanId?: string;
  name: string;
  kind: number;
  startTimeUnixNano: string;
  endTimeUnixNano?: string;
  attributes: Attr[];
  events: SpanEvent[];
  status?: { code: number; message?: string };
}

const SERVER = 2;
const CLIENT = 3;
const ERROR = 2;
const FLUSH_MS = 1000;
const MAX_BATCH = 256;
const MAX_QUEUE = 4096;
const UNMATCHED = "(unmatched)";

const socket = process.env.KILN_OTLP_SOCKET ?? "";
const enabled = socket !== "" && process.env.KILN_TELEMETRY !== "off" && existsSync(socket);
const originalFetch = globalThis.fetch.bind(globalThis);
const current = new AsyncLocalStorage<Span>();
let queue: Span[] = [];
let timer: ReturnType<typeof setTimeout> | undefined;

const hex = (bytes: number) => {
  const b = crypto.getRandomValues(new Uint8Array(bytes));
  return Array.from(b, (x) => x.toString(16).padStart(2, "0")).join("");
};
const nanos = () => (BigInt(Math.round(performance.timeOrigin * 1000 + performance.now() * 1000)) * 1000n).toString();
const str = (key: string, v: string): Attr => ({ key, value: { stringValue: v } });
const int = (key: string, v: number): Attr => ({ key, value: { intValue: String(Math.trunc(v)) } });
const bool = (key: string, v: boolean): Attr => ({ key, value: { boolValue: v } });

function schedule() {
  if (timer === undefined) timer = setTimeout(() => void flush(), FLUSH_MS);
}

function finish(span: Span) {
  if (!enabled) return;
  span.endTimeUnixNano = nanos();
  if (queue.length >= MAX_QUEUE) return; // agent down: drop rather than grow
  queue.push(span);
  if (queue.length >= MAX_BATCH) void flush();
  else schedule();
}

/** Sends everything queued (also called on shutdown). */
export async function flush(): Promise<void> {
  if (timer !== undefined) {
    clearTimeout(timer);
    timer = undefined;
  }
  while (queue.length > 0) {
    const batch = queue.splice(0, MAX_BATCH);
    const body = JSON.stringify({
      resourceSpans: [
        {
          resource: { attributes: [str("telemetry.sdk.name", "kiln-fn"), str("telemetry.sdk.language", "js"), str("process.runtime.name", "bun")] },
          scopeSpans: [{ scope: { name: "kiln-fn-bun" }, spans: batch }],
        },
      ],
    });
    try {
      await originalFetch("http://kiln/v1/traces", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body,
        unix: socket,
        signal: AbortSignal.timeout(2000),
      } as RequestInit);
    } catch {
      // Telemetry is best effort.
    }
  }
}

function exceptionEvent(err: unknown, handled: boolean): SpanEvent {
  const e = err instanceof Error ? err : new Error(String(err));
  return {
    name: "exception",
    timeUnixNano: nanos(),
    attributes: [
      str("exception.type", e.name || "Error"),
      str("exception.message", e.message.slice(0, 4096)),
      str("exception.stacktrace", (e.stack ?? "").slice(0, 16384)),
      bool("kiln.exception.handled", handled),
    ],
  };
}

/** Records an exception on the current request (handled = your code caught it). */
export function recordException(err: unknown, handled = false): void {
  const span = current.getStore();
  if (!span) return;
  span.events.push(exceptionEvent(err, handled));
  if (!handled) span.status = { code: ERROR, message: err instanceof Error ? err.message.slice(0, 512) : String(err) };
}

// /users/42 → /users/:id (numbers, UUIDs, long hex or base64-ish segments), for handlers without a router.
function normalize(path: string): string {
  return (
    path
      .split("/")
      .map((seg) => (/^\d+$|^[0-9a-f-]{16,}$|^[A-Za-z0-9_-]{24,}$/i.test(seg) ? ":id" : seg))
      .join("/") || "/"
  );
}

type RouterRoute = { path?: string; method?: string };
type HonoLike = { router?: { match?: (method: string, path: string) => unknown }; errorHandler?: (err: unknown, c: unknown) => unknown };

/** The Hono route template a request matches (`/users/:id`), from the app's own router. */
function honoRoute(app: HonoLike, method: string, path: string): string | undefined {
  try {
    const result = app.router?.match?.(method === "HEAD" ? "GET" : method, path) as [Array<[[unknown, RouterRoute], unknown]>] | undefined;
    const matches = result?.[0] ?? [];
    let route: string | undefined;
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

type Handler = (req: Request, server: unknown) => Response | undefined | Promise<Response | undefined>;

/** Wraps the function's fetch handler with a `request` span. */
export function instrument(handler: Handler, app: unknown): Handler {
  if (!enabled) return handler;
  const hono = app as HonoLike;
  // Hono turns thrown errors into a 500 in its error handler: record them there (still the request's context).
  if (typeof hono?.errorHandler === "function") {
    const original = hono.errorHandler.bind(hono);
    hono.errorHandler = (err: unknown, c: unknown) => {
      recordException(err, false);
      return original(err, c);
    };
  }
  return (req, server) => {
    const url = new URL(req.url);
    const parent = /^00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/.exec(req.headers.get("traceparent") ?? "");
    // Paths no Hono route matches (scanners, typos) share one name instead of flooding the route list.
    const route = honoRoute(hono, req.method, url.pathname) ?? (typeof hono?.router?.match === "function" ? UNMATCHED : normalize(url.pathname));
    const cold = req.headers.get("x-kiln-cold-start") === "1";
    const span: Span = {
      traceId: parent?.[1] ?? hex(16),
      spanId: hex(8),
      parentSpanId: parent?.[2],
      name: `${req.method} ${route}`,
      kind: SERVER,
      startTimeUnixNano: nanos(),
      attributes: [
        str("kiln.event.type", "request"),
        str("http.request.method", req.method),
        str("http.route", route),
        str("url.path", url.pathname),
        bool("faas.coldstart", cold),
      ],
      events: [],
    };
    const done = (status: number) => {
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
        span.status = { code: ERROR, message: err instanceof Error ? err.message.slice(0, 512) : String(err) };
        done(500);
        throw err;
      }
    });
  };
}

// Outgoing fetch → `outgoing_request` spans under the current request (query strings are not recorded).
if (enabled) {
  globalThis.fetch = Object.assign(
    async (input: RequestInfo | URL, init?: RequestInit) => {
      const parent = current.getStore();
      const req = input instanceof Request ? input : undefined;
      let url: URL | undefined;
      try {
        url = new URL(req ? req.url : String(input));
      } catch {
        return originalFetch(input, init);
      }
      if (!parent || (init as { unix?: string } | undefined)?.unix) return originalFetch(input, init);
      const method = (init?.method ?? req?.method ?? "GET").toUpperCase();
      const span: Span = {
        traceId: parent.traceId,
        spanId: hex(8),
        parentSpanId: parent.spanId,
        name: `${method} ${url.host}`,
        kind: CLIENT,
        startTimeUnixNano: nanos(),
        attributes: [
          str("kiln.event.type", "outgoing_request"),
          str("http.request.method", method),
          str("url.full", `${url.protocol}//${url.host}${url.pathname}`),
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
        span.status = { code: ERROR, message: err instanceof Error ? err.message.slice(0, 512) : String(err) };
        throw err;
      } finally {
        finish(span);
      }
    },
    { preconnect: (originalFetch as unknown as { preconnect?: unknown }).preconnect },
  ) as typeof fetch;
}
