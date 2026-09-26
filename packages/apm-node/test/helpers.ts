import { SpanKind, type Attributes } from '@opentelemetry/api';
import { BasicTracerProvider, InMemorySpanExporter, SimpleSpanProcessor, type ReadableSpan } from '@opentelemetry/sdk-trace-base';
import { createServer, type IncomingMessage, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';
import { KilnSpanProcessor } from '../src/mapping.js';
import { Redactor } from '../src/redact.js';
import { DEFAULT_REDACT_KEYS, type RedactCallback } from '../src/config.js';

/** A local (non-global) provider whose spans go through the Kiln processor into memory. */
export function localProvider(redact?: RedactCallback) {
  const exporter = new InMemorySpanExporter();
  const provider = new BasicTracerProvider({
    spanProcessors: [new KilnSpanProcessor(new SimpleSpanProcessor(exporter), new Redactor(DEFAULT_REDACT_KEYS), redact)],
  });
  const tracer = provider.getTracer('test');

  return {
    exporter,
    tracer,
    finished: (): ReadableSpan[] => exporter.getFinishedSpans(),
    span(name: string, kind: SpanKind, attributes: Attributes, fn?: (s: ReturnType<typeof tracer.startSpan>) => void): ReadableSpan {
      const s = tracer.startSpan(name, { kind, attributes });
      fn?.(s);
      s.end();
      const all = exporter.getFinishedSpans();
      return all[all.length - 1]!;
    },
  };
}

export interface Received {
  path: string;
  contentType: string | undefined;
  body: any;
}

/** Fake OTLP/HTTP receiver (JSON). */
export async function fakeOtlpServer(): Promise<{ url: string; received: Received[]; close(): Promise<void>; waitFor(pred: (r: Received[]) => boolean, ms?: number): Promise<void> }> {
  const received: Received[] = [];
  const server: Server = createServer((req: IncomingMessage, res) => {
    const chunks: Buffer[] = [];
    req.on('data', (c: Buffer) => chunks.push(c));
    req.on('end', () => {
      const raw = Buffer.concat(chunks).toString('utf8');
      let body: unknown = raw;
      try {
        body = JSON.parse(raw);
      } catch {
        // protobuf or garbage: keep raw
      }
      received.push({ path: req.url ?? '', contentType: req.headers['content-type'], body });
      res.writeHead(200, { 'content-type': 'application/json' });
      res.end('{}');
    });
  });

  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
  const { port } = server.address() as AddressInfo;

  return {
    url: `http://127.0.0.1:${port}`,
    received,
    close: () => new Promise((resolve) => server.close(() => resolve())),
    async waitFor(pred, ms = 5000) {
      const until = Date.now() + ms;
      while (!pred(received)) {
        if (Date.now() > until) throw new Error(`timed out; received: ${received.map((r) => r.path).join(', ')}`);
        await new Promise((r) => setTimeout(r, 20));
      }
    },
  };
}

type OtlpAttr = { key: string; value: Record<string, unknown> };

export function attrs(list: OtlpAttr[] | undefined): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const { key, value } of list ?? []) {
    const v = value.stringValue ?? value.intValue ?? value.doubleValue ?? value.boolValue ?? value;
    out[key] = typeof value.intValue === 'string' ? Number(value.intValue) : v;
  }
  return out;
}

/** Flatten spans from received /v1/traces payloads. */
export function otlpSpans(received: Received[]): Array<Record<string, any> & { attrs: Record<string, unknown> }> {
  const spans: Array<Record<string, any> & { attrs: Record<string, unknown> }> = [];
  for (const r of received.filter((x) => x.path === '/v1/traces')) {
    for (const rs of r.body.resourceSpans ?? []) {
      for (const ss of rs.scopeSpans ?? []) {
        for (const s of ss.spans ?? []) spans.push({ ...s, attrs: attrs(s.attributes), resource: attrs(rs.resource?.attributes) });
      }
    }
  }
  return spans;
}
