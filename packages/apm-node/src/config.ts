import type { Attributes } from '@opentelemetry/api';
import { env } from './env.js';

export const DEFAULT_ENDPOINT = 'http://127.0.0.1:4318';
export const DEFAULT_REDACT_KEYS = ['password', 'token', 'secret', 'authorization', 'cookie', 'api_key'];

export type RedactCallback = (attributes: Attributes, eventType: string | undefined) => Attributes | void;

export interface KilnOptions {
  /** Disable everything (env: KILN_APM_ENABLED=false). */
  enabled?: boolean;
  /** OTLP/HTTP base URL (env: KILN_OTLP_ENDPOINT, OTEL_EXPORTER_OTLP_ENDPOINT). Default http://127.0.0.1:4318 */
  endpoint?: string;
  /** service.name (env: KILN_SERVICE_NAME, OTEL_SERVICE_NAME). The agent overrides it with the site slug. */
  serviceName?: string;
  /** Root trace sample ratio 0..1, parent-based (env: KILN_SAMPLE_RATE). Default 1. */
  sampleRate?: number;
  /** Extra resource attributes. */
  resourceAttributes?: Attributes;
  /** Case-insensitive substrings; matching attribute keys / query params are redacted. */
  redactKeys?: string[];
  /** Replace quoted string literals in SQL with `?`. Default true. */
  redactQueryLiterals?: boolean;
  /** Custom redaction, runs after the built-in rules, before export. */
  redact?: RedactCallback;
  /** Enable Node auto-instrumentation (http, undici/fetch, pg, mysql2, ioredis, express, fastify). Default: true on Node. */
  autoInstrument?: boolean;
  /** Export OTLP logs (for @opentelemetry/api-logs / log bridges). Default true. */
  logs?: boolean;
  /** Export OTLP metrics (http server/client duration histograms etc.). Default true on Node. */
  metrics?: boolean;
  /** Record uncaught exceptions / unhandled rejections with kiln.exception.handled=false. Default true. */
  captureProcessErrors?: boolean;
  /** Request headers copied to span attributes (redacted by denylist). Default ['user-agent']. */
  captureRequestHeaders?: string[];
}

export interface ResolvedOptions {
  enabled: boolean;
  endpoint: string;
  serviceName: string | undefined;
  sampleRate: number;
  resourceAttributes: Attributes;
  redactKeys: string[];
  redactQueryLiterals: boolean;
  redact: RedactCallback | undefined;
  autoInstrument: boolean;
  logs: boolean;
  metrics: boolean;
  captureProcessErrors: boolean;
  captureRequestHeaders: string[];
}

function bool(value: string | undefined): boolean | undefined {
  if (value === undefined || value === '') return undefined;
  return !['0', 'false', 'no', 'off'].includes(value.toLowerCase());
}

function ratio(value: string | number | undefined): number | undefined {
  if (value === undefined || value === '') return undefined;
  const n = typeof value === 'number' ? value : Number(value);
  return Number.isFinite(n) ? Math.min(1, Math.max(0, n)) : undefined;
}

export function resolveOptions(options: KilnOptions = {}): ResolvedOptions {
  const e = env();

  return {
    enabled: options.enabled ?? bool(e.KILN_APM_ENABLED) ?? true,
    endpoint: (options.endpoint ?? e.KILN_OTLP_ENDPOINT ?? e.OTEL_EXPORTER_OTLP_ENDPOINT ?? DEFAULT_ENDPOINT).replace(/\/+$/, ''),
    serviceName: options.serviceName ?? e.KILN_SERVICE_NAME ?? e.OTEL_SERVICE_NAME ?? e.npm_package_name,
    sampleRate: ratio(options.sampleRate) ?? ratio(e.KILN_SAMPLE_RATE) ?? 1,
    resourceAttributes: options.resourceAttributes ?? {},
    redactKeys: (options.redactKeys ?? DEFAULT_REDACT_KEYS).map((k) => k.toLowerCase()),
    redactQueryLiterals: options.redactQueryLiterals ?? true,
    redact: options.redact,
    autoInstrument: options.autoInstrument ?? bool(e.KILN_APM_AUTO_INSTRUMENT) ?? true,
    logs: options.logs ?? bool(e.KILN_APM_LOGS) ?? true,
    metrics: options.metrics ?? bool(e.KILN_APM_METRICS) ?? true,
    captureProcessErrors: options.captureProcessErrors ?? true,
    captureRequestHeaders: (options.captureRequestHeaders ?? ['user-agent']).map((h) => h.toLowerCase()),
  };
}
