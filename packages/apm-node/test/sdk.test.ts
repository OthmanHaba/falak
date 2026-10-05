import { afterEach, describe, expect, test } from 'bun:test';
import { SpanKind, SpanStatusCode } from '@opentelemetry/api';
import { logs, SeverityNumber } from '@opentelemetry/api-logs';
import { InMemorySpanExporter, SimpleSpanProcessor } from '@opentelemetry/sdk-trace-base';
import { recordException, shutdown, start, withFalakRequest } from '../src/index.js';
import { attrs, fakeOtlpServer, otlpSpans } from './helpers.js';

const base = { metrics: false, captureProcessErrors: false } as const;

function memory(options: Parameters<typeof start>[0] = {}) {
  const exporter = new InMemorySpanExporter();
  const handle = start({ ...base, logs: false, ...options }, { mode: 'basic', spanExporter: exporter, spanProcessorFactory: (e) => new SimpleSpanProcessor(e) });
  return { exporter, handle };
}

afterEach(async () => {
  await shutdown();
});

describe('withFalakRequest (Bun / Deno fetch handlers)', () => {
  test('records a SERVER request span with contract attributes', async () => {
    const { exporter } = memory();
    const handler = withFalakRequest(async () => new Response('ok', { status: 201 }), { route: '/users/:id', user: () => 42 });

    const res = await handler(new Request('http://shop.test/users/7?token=abc', { headers: { 'user-agent': 'bun-test' } }));
    expect(res.status).toBe(201);

    const [span] = exporter.getFinishedSpans();
    expect(span!.kind).toBe(SpanKind.SERVER);
    expect(span!.name).toBe('GET /users/:id');
    expect(span!.attributes).toMatchObject({
      'falak.event.type': 'request',
      'http.request.method': 'GET',
      'http.route': '/users/:id',
      'http.response.status_code': 201,
      'url.path': '/users/7',
      'url.query': 'token=%5Bredacted%5D',
      'enduser.id': '42',
      'user_agent.original': 'bun-test',
    });
  });

  test('continues incoming traceparent', async () => {
    const { exporter } = memory();
    await withFalakRequest(() => new Response('ok'))(
      new Request('http://x.test/', { headers: { traceparent: '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01' } }),
    );
    const [span] = exporter.getFinishedSpans();
    expect(span!.spanContext().traceId).toBe('0af7651916cd43dd8448eb211c80319c');
    expect(span!.parentSpanContext?.spanId).toBe('b7ad6b7169203331');
  });

  test('thrown errors are unhandled exceptions and rethrown', async () => {
    const { exporter } = memory();
    const handler = withFalakRequest(() => {
      throw new RangeError('handler exploded');
    });

    await expect(handler(new Request('http://x.test/boom'))).rejects.toThrow('handler exploded');

    const [span] = exporter.getFinishedSpans();
    expect(span!.status.code).toBe(SpanStatusCode.ERROR);
    expect(span!.attributes['http.response.status_code']).toBe(500);
    const event = span!.events.find((e) => e.name === 'exception')!;
    expect(event.attributes).toMatchObject({ 'exception.type': 'RangeError', 'falak.exception.handled': false });
  });

  test('handled exceptions inside the handler attach to the request span', async () => {
    const { exporter } = memory();
    await withFalakRequest(() => {
      recordException(new Error('retrying'));
      return new Response('ok');
    })(new Request('http://x.test/'));

    const [span] = exporter.getFinishedSpans();
    expect(span!.status.code).toBe(SpanStatusCode.UNSET);
    expect(span!.events[0]!.attributes!['falak.exception.handled']).toBe(true);
  });
});

describe('sampling', () => {
  test('sampleRate 0 drops root traces', async () => {
    const { exporter } = memory({ sampleRate: 0 });
    await withFalakRequest(() => new Response('ok'))(new Request('http://x.test/'));
    expect(exporter.getFinishedSpans()).toHaveLength(0);
  });

  test('parent-based: a sampled upstream trace is kept even at rate 0', async () => {
    const { exporter } = memory({ sampleRate: 0 });
    await withFalakRequest(() => new Response('ok'))(
      new Request('http://x.test/', { headers: { traceparent: '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01' } }),
    );
    expect(exporter.getFinishedSpans()).toHaveLength(1);
  });

  test('FALAK_SAMPLE_RATE env is honoured and clamped', () => {
    process.env.FALAK_SAMPLE_RATE = '7';
    const { handle } = memory();
    expect(handle.options.sampleRate).toBe(1);
    delete process.env.FALAK_SAMPLE_RATE;
  });

  test('disabled via FALAK_APM_ENABLED', async () => {
    process.env.FALAK_APM_ENABLED = 'false';
    const { exporter, handle } = memory();
    expect(handle.options.enabled).toBe(false);
    await withFalakRequest(() => new Response('ok'))(new Request('http://x.test/'));
    expect(exporter.getFinishedSpans()).toHaveLength(0);
    delete process.env.FALAK_APM_ENABLED;
  });
});

describe('OTLP exporter roundtrip (fake agent)', () => {
  test('traces and logs arrive as OTLP/HTTP JSON with resource attrs, mapping and redaction', async () => {
    const agent = await fakeOtlpServer();
    process.env.FALAK_SITE_ID = '01JSITE';
    process.env.FALAK_RELEASE_ID = '01JREL';

    try {
      const handle = start({ ...base, endpoint: agent.url, serviceName: 'shop-example-com' }, { mode: 'basic' });

      const handler = withFalakRequest(
        async () => {
          logs.getLogger('app').emit({ body: 'order placed', severityNumber: SeverityNumber.INFO, severityText: 'INFO', attributes: { order: 7, password: 'hunter2' } });
          return new Response('ok');
        },
        { route: '/orders', captureHeaders: ['authorization'] },
      );

      await handler(new Request('http://shop.test/orders?secret=s', { method: 'POST', headers: { authorization: 'Bearer abc' } }));
      await handle.forceFlush();
      await agent.waitFor((r) => r.some((x) => x.path === '/v1/traces') && r.some((x) => x.path === '/v1/logs'));

      const traces = agent.received.find((r) => r.path === '/v1/traces')!;
      expect(traces.contentType).toContain('application/json');

      const [span] = otlpSpans(agent.received);
      expect(span!.kind).toBe(2);
      expect(span!.traceId).toMatch(/^[0-9a-f]{32}$/);
      expect(span!.attrs).toMatchObject({
        'falak.event.type': 'request',
        'http.request.method': 'POST',
        'http.route': '/orders',
        'url.path': '/orders',
        'url.query': 'secret=%5Bredacted%5D',
        'http.response.status_code': 200,
        'http.request.header.authorization': '[redacted]',
      });
      expect(span!.resource).toMatchObject({ 'service.name': 'shop-example-com', 'falak.site.id': '01JSITE', 'falak.release.id': '01JREL' });

      const logPayload = agent.received.find((r) => r.path === '/v1/logs')!.body;
      const record = logPayload.resourceLogs[0].scopeLogs[0].logRecords[0];
      expect(record.body.stringValue).toBe('order placed');
      expect(record.traceId).toBe(span!.traceId);
      expect(record.spanId).toBe(span!.spanId);
      expect(attrs(record.attributes)).toMatchObject({ order: 7, password: '[redacted]' });
    } finally {
      delete process.env.FALAK_SITE_ID;
      delete process.env.FALAK_RELEASE_ID;
      await shutdown();
      await agent.close();
    }
  });

  test('an unreachable agent never throws into the app and flush stays bounded', async () => {
    const handle = start({ ...base, endpoint: 'http://127.0.0.1:1', logs: false }, { mode: 'basic' });
    const res = await withFalakRequest(() => new Response('still ok'))(new Request('http://x.test/'));
    expect(await res.text()).toBe('still ok');
    const t0 = Date.now();
    await handle.forceFlush();
    expect(Date.now() - t0).toBeLessThan(3_000);
  }, 10_000);
});
