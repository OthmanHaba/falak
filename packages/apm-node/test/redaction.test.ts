import { describe, expect, test } from 'bun:test';
import { SpanKind } from '@opentelemetry/api';
import { Redactor } from '../src/redact.js';
import { DEFAULT_REDACT_KEYS } from '../src/config.js';
import { localProvider } from './helpers.js';

describe('redaction', () => {
  const r = new Redactor(DEFAULT_REDACT_KEYS);

  test('default denylist on attribute keys (headers etc.)', () => {
    const attrs = r.attributes({
      'http.request.header.authorization': 'Bearer x',
      'http.request.header.cookie': 'sid=1',
      'http.request.header.x-csrf-token': 'y',
      'app.client_secret': 'z',
      'app.api_key': 1234,
      'http.request.header.accept': 'text/html',
    });
    expect(attrs).toEqual({
      'http.request.header.authorization': '[redacted]',
      'http.request.header.cookie': '[redacted]',
      'http.request.header.x-csrf-token': '[redacted]',
      'app.client_secret': '[redacted]',
      'app.api_key': '[redacted]',
      'http.request.header.accept': 'text/html',
    });
  });

  test('urls: query params and userinfo password', () => {
    expect(r.url('https://u:pw@h.test/p?Password=1&q=shoes#frag')).toBe('https://u:[redacted]@h.test/p?Password=%5Bredacted%5D&q=shoes#frag');
    expect(r.url('https://h.test/p')).toBe('https://h.test/p');
    expect(r.value('url.query', 'token=abc&x=1')).toBe('token=%5Bredacted%5D&x=1');
  });

  test('sql literals', () => {
    expect(r.sql("update t set a = 'it''s' where b = 'x\\'y' and c = ?")).toBe('update t set a = ? where b = ? and c = ?');
    expect(new Redactor([], false).sql("select 'a'")).toBe("select 'a'");
  });

  test('custom keys and callback run before export; broken callbacks are ignored', () => {
    const p = localProvider((attributes, type) => {
      if (type === 'request') attributes['client.address'] = '0.0.0.0';
    });
    const span = p.span('GET', SpanKind.SERVER, { 'http.request.method': 'GET', 'client.address': '10.1.2.3' });
    expect(span.attributes['client.address']).toBe('0.0.0.0');

    const broken = localProvider(() => {
      throw new Error('bad');
    });
    const ok = broken.span('GET', SpanKind.SERVER, { 'http.request.method': 'GET' });
    expect(ok.attributes['falak.event.type']).toBe('request');

    const custom = new Redactor(['ssn']);
    expect(custom.attributes({ 'user.ssn': '1', 'user.password': 'p' })).toEqual({ 'user.ssn': '[redacted]', 'user.password': 'p' });
  });

  test('callback may return a replacement attribute map', () => {
    const p = localProvider(() => ({ 'falak.event.type': 'request', only: true }));
    const span = p.span('GET', SpanKind.SERVER, { 'http.request.method': 'GET', extra: 1 });
    expect(span.attributes).toEqual({ 'falak.event.type': 'request', only: true });
  });
});
