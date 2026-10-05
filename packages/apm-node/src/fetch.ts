import { context, propagation, SpanKind, SpanStatusCode, trace, type Attributes, type TextMapGetter } from '@opentelemetry/api';
import { recordException } from './exceptions.js';
import { SCOPE } from './version.js';

export type FetchHandler<Args extends unknown[] = unknown[]> = (request: Request, ...rest: Args) => Response | Promise<Response>;

export interface WithFalakRequestOptions {
  /** Low-cardinality route template, e.g. "/users/:id" (or a function of the request). */
  route?: string | ((request: Request) => string | undefined);
  /** Authenticated user id for `enduser.id`. */
  user?: (request: Request) => string | number | undefined | null;
  /** Request headers copied to `http.request.header.<name>` (redacted by denylist on export). */
  captureHeaders?: string[];
}

const headerGetter: TextMapGetter<Headers> = {
  keys: (carrier) => [...carrier.keys()],
  get: (carrier, key) => carrier.get(key) ?? undefined,
};

/**
 * Wrap a fetch-style handler (Bun.serve, Deno.serve, Hono `app.fetch`, Cloudflare-style
 * workers, Next route handlers) in a SERVER span with falak.event.type=request.
 */
export function withFalakRequest<Args extends unknown[]>(handler: FetchHandler<Args>, options: WithFalakRequestOptions = {}): FetchHandler<Args> {
  const tracer = trace.getTracer(SCOPE);

  return async function falakRequest(request: Request, ...rest: Args): Promise<Response> {
    const url = new URL(request.url);
    const route = typeof options.route === 'function' ? options.route(request) : options.route;
    const parent = propagation.extract(context.active(), request.headers, headerGetter);

    const attributes: Attributes = {
      'falak.event.type': 'request',
      'http.request.method': request.method,
      'url.path': url.pathname,
      'url.scheme': url.protocol.replace(':', ''),
      'server.address': url.hostname,
      'user_agent.original': request.headers.get('user-agent') ?? undefined,
    };
    if (url.search) attributes['url.query'] = url.search.slice(1);
    if (route) attributes['http.route'] = route;
    for (const name of options.captureHeaders ?? []) {
      const value = request.headers.get(name);
      if (value !== null) attributes[`http.request.header.${name.toLowerCase()}`] = value;
    }

    const name = route ? `${request.method} ${route}` : request.method;

    return tracer.startActiveSpan(name, { kind: SpanKind.SERVER, attributes }, parent, async (span) => {
      try {
        const response = await handler(request, ...rest);
        span.setAttribute('http.response.status_code', response.status);
        if (response.status >= 500) span.setStatus({ code: SpanStatusCode.ERROR, message: String(response.status) });
        return response;
      } catch (error) {
        span.setAttribute('http.response.status_code', 500);
        recordException(error, { handled: false, span });
        throw error;
      } finally {
        try {
          const user = options.user?.(request);
          if (user !== undefined && user !== null) span.setAttribute('enduser.id', String(user));
        } catch {
          // ignore
        }
        span.end();
      }
    });
  };
}

/** Set `enduser.id` on the active span (call from auth middleware). */
export function setUser(id: string | number | undefined | null): void {
  if (id === undefined || id === null) return;
  trace.getActiveSpan()?.setAttribute('enduser.id', String(id));
}
