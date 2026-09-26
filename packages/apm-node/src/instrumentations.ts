import type { Span } from '@opentelemetry/api';
import type { Instrumentation } from '@opentelemetry/instrumentation';
import { ExpressInstrumentation } from '@opentelemetry/instrumentation-express';
import { FastifyInstrumentation } from '@opentelemetry/instrumentation-fastify';
import { HttpInstrumentation } from '@opentelemetry/instrumentation-http';
import { IORedisInstrumentation } from '@opentelemetry/instrumentation-ioredis';
import { MySQL2Instrumentation } from '@opentelemetry/instrumentation-mysql2';
import { PgInstrumentation } from '@opentelemetry/instrumentation-pg';
import { UndiciInstrumentation } from '@opentelemetry/instrumentation-undici';
import type { ResolvedOptions } from './config.js';

const CACHE_READS = new Set(['get', 'mget', 'hget', 'hmget', 'hgetall', 'getex', 'getdel', 'exists']);
const CACHE_WRITES = new Set(['set', 'setex', 'psetex', 'setnx', 'mset', 'msetnx', 'hset', 'hmset', 'hsetnx', 'incr', 'incrby', 'decr', 'decrby', 'append']);
const CACHE_FORGETS = new Set(['del', 'unlink', 'hdel', 'getdel']);

function isEmpty(response: unknown): boolean {
  if (response === null || response === undefined || response === 0) return true;
  if (Array.isArray(response)) return response.every((r) => r === null || r === undefined);
  if (typeof response === 'object') return Object.keys(response as object).length === 0;
  return false;
}

/** ioredis responseHook: turn key/value commands into kiln cache ops (hit / miss / write / forget). */
export function redisCacheHook(span: Span, cmdName: string, cmdArgs: unknown[], response: unknown): void {
  const cmd = cmdName.toLowerCase();
  let op: string | undefined;

  if (CACHE_FORGETS.has(cmd) && cmd !== 'getdel') op = 'forget';
  else if (CACHE_READS.has(cmd)) op = isEmpty(response) ? 'miss' : 'hit';
  else if (CACHE_WRITES.has(cmd)) op = 'write';

  if (!op) return;

  span.setAttribute('kiln.event.type', 'cache');
  span.setAttribute('kiln.cache.op', op);
  span.setAttribute('kiln.cache.key', String(cmdArgs[0] ?? ''));
  span.setAttribute('kiln.cache.store', 'redis');
}

/** Auto-instrumentations. Each only activates when its target module is loaded. */
export function nodeInstrumentations(options: ResolvedOptions): Instrumentation[] {
  const headers = options.captureRequestHeaders;
  const ignoreOutgoing = (url: string) => url.startsWith(options.endpoint);

  return [
    new HttpInstrumentation({
      headersToSpanAttributes: { server: { requestHeaders: headers }, client: { requestHeaders: [] } },
      ignoreOutgoingRequestHook: (req) => {
        const host = typeof req.host === 'string' ? req.host : req.hostname ?? '';
        const port = req.port !== undefined ? `:${req.port}` : '';
        return ignoreOutgoing(`${req.protocol ?? 'http:'}//${host}${port}`);
      },
    }),
    new UndiciInstrumentation({
      ignoreRequestHook: (req) => ignoreOutgoing(`${req.origin}`),
    }),
    new PgInstrumentation({ enhancedDatabaseReporting: false }),
    new MySQL2Instrumentation(),
    new IORedisInstrumentation({ responseHook: redisCacheHook as never }),
    new ExpressInstrumentation(),
    new FastifyInstrumentation(),
  ];
}
