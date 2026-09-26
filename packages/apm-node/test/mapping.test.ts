import { describe, expect, test } from 'bun:test';
import { SpanKind, SpanStatusCode } from '@opentelemetry/api';
import { redisCacheHook } from '../src/instrumentations.js';
import { localProvider } from './helpers.js';

describe('span mapping to kiln.event.type', () => {
  test('SERVER http spans become request (old semconv normalised)', () => {
    const p = localProvider();
    const span = p.span('GET', SpanKind.SERVER, {
      'http.method': 'GET',
      'http.target': '/users/7?token=abc',
      'http.route': '/users/:id',
      'http.status_code': 200,
    });

    expect(span.attributes).toMatchObject({
      'kiln.event.type': 'request',
      'http.request.method': 'GET',
      'http.route': '/users/:id',
      'http.response.status_code': 200,
      'url.path': '/users/7',
      'http.target': '/users/7?token=%5Bredacted%5D',
    });
    expect(span.name).toBe('GET /users/:id');
    expect(span.status.code).toBe(SpanStatusCode.UNSET);
  });

  test('5xx requests are ERROR', () => {
    const p = localProvider();
    const span = p.span('POST', SpanKind.SERVER, { 'http.request.method': 'POST', 'url.path': '/x', 'http.response.status_code': 503 });
    expect(span.status.code).toBe(SpanStatusCode.ERROR);
  });

  test('CLIENT http spans become outgoing_request with redacted url.full', () => {
    const p = localProvider();
    const span = p.span('GET', SpanKind.CLIENT, {
      'http.request.method': 'GET',
      'url.full': 'https://api.example.com/v1?api_key=abc&page=2',
      'http.response.status_code': 201,
    });

    expect(span.attributes).toMatchObject({
      'kiln.event.type': 'outgoing_request',
      'url.full': 'https://api.example.com/v1?api_key=%5Bredacted%5D&page=2',
      'http.response.status_code': 201,
    });
  });

  test('db spans become query with contract attributes and SQL literals stripped', () => {
    const p = localProvider();
    const span = p.span('SELECT shop', SpanKind.CLIENT, {
      'db.system': 'postgresql',
      'db.statement': "SELECT * FROM users WHERE email = 'a@b.c' AND id = $1",
      'db.name': 'shop',
      'net.peer.name': 'db.internal',
      'net.peer.port': 5432,
    });

    expect(span.attributes).toMatchObject({
      'kiln.event.type': 'query',
      'db.system.name': 'postgresql',
      'db.query.text': 'SELECT * FROM users WHERE email = ? AND id = $1',
      'db.namespace': 'shop',
      'kiln.query.connection': 'db.internal:5432',
    });
  });

  test('mysql2 stable semconv spans map too', () => {
    const p = localProvider();
    const span = p.span('select', SpanKind.CLIENT, { 'db.system.name': 'mysql', 'db.query.text': 'select 1', 'db.namespace': 'app', 'server.address': 'localhost' });
    expect(span.attributes).toMatchObject({ 'kiln.event.type': 'query', 'db.system.name': 'mysql', 'kiln.query.connection': 'localhost' });
  });

  test('redis key/value commands become cache ops via the ioredis response hook', () => {
    const p = localProvider();
    const hit = p.span('get', SpanKind.CLIENT, { 'db.system': 'redis' }, (s) => redisCacheHook(s, 'get', ['users:1'], '{"id":1}'));
    const miss = p.span('get', SpanKind.CLIENT, { 'db.system': 'redis' }, (s) => redisCacheHook(s, 'GET', ['users:2'], null));
    const write = p.span('set', SpanKind.CLIENT, { 'db.system': 'redis' }, (s) => redisCacheHook(s, 'set', ['users:3', 'x'], 'OK'));
    const forget = p.span('del', SpanKind.CLIENT, { 'db.system': 'redis' }, (s) => redisCacheHook(s, 'del', ['password_reset:3'], 1));
    const other = p.span('publish', SpanKind.CLIENT, { 'db.system': 'redis' }, (s) => redisCacheHook(s, 'publish', ['ch', 'm'], 1));

    expect([hit, miss, write].map((s) => [s.attributes['kiln.event.type'], s.attributes['kiln.cache.op'], s.attributes['kiln.cache.key'], s.attributes['kiln.cache.store']])).toEqual([
      ['cache', 'hit', 'users:1', 'redis'],
      ['cache', 'miss', 'users:2', 'redis'],
      ['cache', 'write', 'users:3', 'redis'],
    ]);
    expect(forget.attributes['kiln.cache.op']).toBe('forget');
    expect(forget.attributes['kiln.cache.key']).toBe('[redacted]');
    expect(other.attributes['kiln.event.type']).toBe('query');
  });

  test('unrelated spans are left untyped', () => {
    const p = localProvider();
    const span = p.span('middleware - cors', SpanKind.INTERNAL, { 'express.type': 'middleware' });
    expect(span.attributes['kiln.event.type']).toBeUndefined();
  });
});
