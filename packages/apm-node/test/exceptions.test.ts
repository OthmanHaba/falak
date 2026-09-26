import { describe, expect, test } from 'bun:test';
import { SpanKind, SpanStatusCode } from '@opentelemetry/api';
import { recordException } from '../src/exceptions.js';
import { localProvider } from './helpers.js';

function exceptionEvent(span: { events: { name: string; attributes?: Record<string, unknown> }[] }) {
  return span.events.find((e) => e.name === 'exception')?.attributes ?? {};
}

describe('exceptions', () => {
  test('handled exceptions: event with kiln.exception.handled=true, status untouched', () => {
    const p = localProvider();
    const span = p.span('GET', SpanKind.SERVER, { 'http.request.method': 'GET' }, (s) => {
      expect(recordException(new TypeError('soft'), { span: s })).toBe(true);
    });

    expect(exceptionEvent(span)).toMatchObject({
      'exception.type': 'TypeError',
      'exception.message': 'soft',
      'kiln.exception.handled': true,
    });
    expect(String(exceptionEvent(span)['exception.stacktrace'])).toContain('TypeError: soft');
    expect(span.status.code).toBe(SpanStatusCode.UNSET);
  });

  test('unhandled exceptions mark the span ERROR', () => {
    const p = localProvider();
    const span = p.span('GET', SpanKind.SERVER, { 'http.request.method': 'GET' }, (s) => {
      recordException(new Error('boom'), { span: s, handled: false });
    });

    expect(exceptionEvent(span)['kiln.exception.handled']).toBe(false);
    expect(span.status).toEqual({ code: SpanStatusCode.ERROR, message: 'boom' });
  });

  test('instrumentation-recorded exceptions get the flag from span status', () => {
    const p = localProvider();
    const escaped = p.span('GET', SpanKind.SERVER, { 'http.request.method': 'GET' }, (s) => {
      s.recordException(new Error('escaped'));
      s.setStatus({ code: SpanStatusCode.ERROR });
    });
    const caught = p.span('work', SpanKind.INTERNAL, {}, (s) => s.recordException(new Error('caught')));

    expect(exceptionEvent(escaped)['kiln.exception.handled']).toBe(false);
    expect(exceptionEvent(caught)['kiln.exception.handled']).toBe(true);
    expect(caught.status.code).toBe(SpanStatusCode.UNSET);
  });

  test('non-Error values and missing spans', () => {
    const p = localProvider();
    const span = p.span('x', SpanKind.INTERNAL, {}, (s) => recordException('string thrown', { span: s }));
    expect(exceptionEvent(span)).toMatchObject({ 'exception.type': 'string', 'exception.message': 'string thrown' });
    expect(recordException(new Error('no span'))).toBe(false);
  });
});
