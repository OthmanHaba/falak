import { SpanKind, SpanStatusCode, type Attributes, type Context } from '@opentelemetry/api';
import type { ReadableSpan, Span, SpanProcessor } from '@opentelemetry/sdk-trace-base';
import type { RedactCallback } from './config.js';
import { Redactor } from './redact.js';

export type FalakEventType = 'request' | 'outgoing_request' | 'query' | 'cache';

const CACHE_SYSTEMS = new Set(['redis', 'memcached', 'valkey']);

const DB_SYSTEM_NAMES: Record<string, string> = {
  postgresql: 'postgresql',
  postgres: 'postgresql',
  pg: 'postgresql',
  mysql: 'mysql',
  mariadb: 'mariadb',
  sqlite: 'sqlite',
  mssql: 'microsoft.sql_server',
};

type Mutable = { attributes: Attributes; status: { code: SpanStatusCode; message?: string }; name: string };

function str(v: unknown): string | undefined {
  return typeof v === 'string' && v !== '' ? v : typeof v === 'number' ? String(v) : undefined;
}

function num(v: unknown): number | undefined {
  if (typeof v === 'number') return v;
  if (typeof v === 'string' && v !== '' && Number.isFinite(Number(v))) return Number(v);
  return undefined;
}

function setIfMissing(attrs: Attributes, key: string, value: string | number | boolean | undefined): void {
  if (attrs[key] === undefined && value !== undefined) attrs[key] = value;
}

/** Classify a span into a Falak event type (or undefined for spans we leave untouched). */
export function classify(span: Pick<ReadableSpan, 'kind' | 'attributes'>): FalakEventType | undefined {
  const a = span.attributes;
  const existing = str(a['falak.event.type']);
  if (existing) return existing as FalakEventType;

  const dbSystem = str(a['db.system.name']) ?? str(a['db.system']);
  if (dbSystem) {
    return CACHE_SYSTEMS.has(dbSystem) && a['falak.cache.op'] !== undefined ? 'cache' : 'query';
  }

  const isHttp = a['http.request.method'] !== undefined || a['http.method'] !== undefined;
  if (isHttp && span.kind === SpanKind.SERVER) return 'request';
  if (isHttp && span.kind === SpanKind.CLIENT) return 'outgoing_request';

  return undefined;
}

/**
 * Normalise a finished span to the Falak telemetry contract: falak.event.type, stable semconv
 * attribute names, exception handling flags, status, and redaction. Mutates in place.
 */
export function mapSpan(span: ReadableSpan, redactor: Redactor, redact?: RedactCallback): FalakEventType | undefined {
  const m = span as unknown as Mutable;
  const a = m.attributes;
  const type = classify(span);

  if (type) a['falak.event.type'] = type;

  switch (type) {
    case 'request': {
      setIfMissing(a, 'http.request.method', str(a['http.method']));
      setIfMissing(a, 'http.response.status_code', num(a['http.status_code']));
      setIfMissing(a, 'http.route', str(a['next.route']));
      const target = str(a['http.target']);
      setIfMissing(a, 'url.path', target?.split('?')[0]);
      const route = str(a['http.route']);
      const method = str(a['http.request.method']);
      if (route && method && (m.name === method || m.name === `${method}`)) m.name = `${method} ${route}`;
      const status = num(a['http.response.status_code']);
      if (status !== undefined && status >= 500) {
        m.status = { code: SpanStatusCode.ERROR, message: m.status.message ?? String(status) };
      }
      break;
    }
    case 'outgoing_request': {
      setIfMissing(a, 'http.request.method', str(a['http.method']));
      setIfMissing(a, 'http.response.status_code', num(a['http.status_code']));
      setIfMissing(a, 'url.full', str(a['http.url']));
      break;
    }
    case 'query': {
      const system = str(a['db.system.name']) ?? str(a['db.system']) ?? 'other_sql';
      a['db.system.name'] = DB_SYSTEM_NAMES[system] ?? system;
      setIfMissing(a, 'db.query.text', str(a['db.statement']));
      setIfMissing(a, 'db.namespace', str(a['db.name']));
      const host = str(a['server.address']) ?? str(a['net.peer.name']);
      const port = str(a['server.port']) ?? str(a['net.peer.port']);
      setIfMissing(a, 'falak.query.connection', host ? (port ? `${host}:${port}` : host) : 'default');
      setIfMissing(a, 'db.namespace', '');
      break;
    }
    case 'cache': {
      a['falak.cache.store'] ??= str(a['db.system.name']) ?? str(a['db.system']) ?? 'redis';
      setIfMissing(a, 'falak.cache.key', '');
      break;
    }
  }

  // Exceptions: every `exception` event carries falak.exception.handled.
  for (const event of span.events) {
    if (event.name !== 'exception') continue;
    const attrs = ((event as { attributes?: Attributes }).attributes ??= {});
    if (attrs['falak.exception.handled'] === undefined) {
      // Instrumentations record escaping errors together with an ERROR status.
      attrs['falak.exception.handled'] = m.status.code !== SpanStatusCode.ERROR && attrs['exception.escaped'] !== true;
    }
    if (attrs['falak.exception.handled'] === false && m.status.code !== SpanStatusCode.ERROR) {
      m.status = { code: SpanStatusCode.ERROR, message: str(attrs['exception.message']) ?? '' };
    }
  }

  redactor.attributes(a);

  if (redact) {
    try {
      const result = redact(a, type);
      if (result && result !== a) {
        for (const key of Object.keys(a)) delete a[key];
        Object.assign(a, result);
      }
    } catch {
      // A broken user callback must never break the host application.
    }
  }

  return type;
}

/**
 * Wraps the exporting processor: maps + redacts each span right before it is queued for export.
 */
export class FalakSpanProcessor implements SpanProcessor {
  constructor(
    private readonly delegate: SpanProcessor,
    private readonly redactor: Redactor,
    private readonly redact?: RedactCallback,
  ) {}

  onStart(span: Span, parentContext: Context): void {
    this.delegate.onStart(span, parentContext);
  }

  onEnding(span: Span): void {
    this.delegate.onEnding?.(span);
  }

  onEnd(span: ReadableSpan): void {
    try {
      mapSpan(span, this.redactor, this.redact);
    } catch {
      // never throw into the app
    }
    this.delegate.onEnd(span);
  }

  forceFlush(): Promise<void> {
    return this.delegate.forceFlush();
  }

  shutdown(): Promise<void> {
    return this.delegate.shutdown();
  }
}
