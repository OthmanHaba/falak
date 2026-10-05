import { context, propagation, trace } from '@opentelemetry/api';
import { logs } from '@opentelemetry/api-logs';
import { AsyncLocalStorageContextManager } from '@opentelemetry/context-async-hooks';
import { CompositePropagator, W3CBaggagePropagator, W3CTraceContextPropagator } from '@opentelemetry/core';
import { OTLPLogExporter } from '@opentelemetry/exporter-logs-otlp-http';
import { OTLPMetricExporter } from '@opentelemetry/exporter-metrics-otlp-http';
import { OTLPTraceExporter } from '@opentelemetry/exporter-trace-otlp-http';
import { resourceFromAttributes } from '@opentelemetry/resources';
import { BatchLogRecordProcessor, LoggerProvider, type LogRecordProcessor, type ReadWriteLogRecord } from '@opentelemetry/sdk-logs';
import { PeriodicExportingMetricReader } from '@opentelemetry/sdk-metrics';
import { NodeSDK } from '@opentelemetry/sdk-node';
import {
  BasicTracerProvider,
  BatchSpanProcessor,
  ParentBasedSampler,
  TraceIdRatioBasedSampler,
  type SpanExporter,
  type SpanProcessor,
} from '@opentelemetry/sdk-trace-base';
import type { Context } from '@opentelemetry/api';
import { resolveOptions, type FalakOptions, type ResolvedOptions } from './config.js';
import { runtime } from './env.js';
import { installProcessHandlers } from './exceptions.js';
import { nodeInstrumentations } from './instrumentations.js';
import { FalakSpanProcessor } from './mapping.js';
import { REDACTED, Redactor } from './redact.js';
import { falakResourceAttributes } from './resource.js';

export interface FalakHandle {
  readonly options: ResolvedOptions;
  /** Export everything buffered. Bounded in time; never rejects. */
  forceFlush(): Promise<void>;
  /** Flush and stop. Bounded in time; never rejects. */
  shutdown(): Promise<void>;
}

export interface StartOverrides {
  /** Replace the OTLP span exporter (tests, custom transports). */
  spanExporter?: SpanExporter;
  /** Use a custom span processor instead of BatchSpanProcessor around the exporter. */
  spanProcessorFactory?: (exporter: SpanExporter) => SpanProcessor;
  /** Force the lightweight (non-NodeSDK) provider, as used on Bun and Deno. */
  mode?: 'node' | 'basic';
}

let active: FalakHandle | undefined;

/** Per-export budget; the agent is local, so anything slower means it is down. */
const EXPORT_TIMEOUT_MS = 2_000;

/** Resolve after `ms` at the latest; never rejects. */
function bounded(promise: Promise<unknown>, ms: number): Promise<void> {
  let timer: ReturnType<typeof setTimeout> | undefined;
  return Promise.race([
    promise.then(
      () => {},
      () => {},
    ),
    new Promise<void>((resolve) => {
      timer = setTimeout(resolve, ms);
      (timer as { unref?: () => void }).unref?.();
    }),
  ]).finally(() => clearTimeout(timer));
}

/** Redacts log attributes by the same denylist as spans. */
class FalakLogProcessor implements LogRecordProcessor {
  constructor(
    private readonly delegate: LogRecordProcessor,
    private readonly redactor: Redactor,
  ) {}

  onEmit(record: ReadWriteLogRecord, ctx?: Context): void {
    try {
      for (const key of Object.keys(record.attributes)) {
        if (this.redactor.isSensitive(key)) record.setAttribute(key, REDACTED);
      }
    } catch {
      // never throw into the app
    }
    this.delegate.onEmit(record, ctx);
  }

  forceFlush(): Promise<void> {
    return this.delegate.forceFlush();
  }

  shutdown(): Promise<void> {
    return this.delegate.shutdown();
  }
}

const noop: FalakHandle = {
  options: resolveOptions({ enabled: false }),
  forceFlush: async () => {},
  shutdown: async () => {},
};

/**
 * Start Falak APM. Idempotent: a second call returns the running handle.
 *
 * Node: NodeSDK + auto-instrumentations. Bun / Deno: a BasicTracerProvider with the same
 * exporters and processors (instrument handlers with `withFalakRequest`).
 */
export function start(options: FalakOptions = {}, overrides: StartOverrides = {}): FalakHandle {
  if (active) return active;

  const opts = resolveOptions(options);
  if (!opts.enabled) return noop;

  const redactor = new Redactor(opts.redactKeys, opts.redactQueryLiterals);
  const resource = resourceFromAttributes(falakResourceAttributes(opts));
  const sampler = new ParentBasedSampler({ root: new TraceIdRatioBasedSampler(opts.sampleRate) });
  const exporter = overrides.spanExporter ?? new OTLPTraceExporter({ url: `${opts.endpoint}/v1/traces`, timeoutMillis: EXPORT_TIMEOUT_MS });
  const inner = overrides.spanProcessorFactory?.(exporter) ?? new BatchSpanProcessor(exporter);
  const spanProcessor = new FalakSpanProcessor(inner, redactor, opts.redact);
  const logProcessor = opts.logs
    ? new FalakLogProcessor(new BatchLogRecordProcessor({ exporter: new OTLPLogExporter({ url: `${opts.endpoint}/v1/logs`, timeoutMillis: EXPORT_TIMEOUT_MS }) }), redactor)
    : undefined;

  const mode = overrides.mode ?? (runtime() === 'node' ? 'node' : 'basic');
  let uninstall = () => {};
  let flush: () => Promise<void>;
  let stop: () => Promise<void>;

  if (mode === 'node') {
    // Prefer stable HTTP/DB semantic conventions from the instrumentations.
    const penv = (globalThis as { process?: { env: Record<string, string | undefined> } }).process?.env;
    if (penv && penv.OTEL_SEMCONV_STABILITY_OPT_IN === undefined) penv.OTEL_SEMCONV_STABILITY_OPT_IN = 'http,database';

    const sdk = new NodeSDK({
      resource,
      autoDetectResources: false,
      sampler,
      spanProcessors: [spanProcessor],
      logRecordProcessors: logProcessor ? [logProcessor] : [],
      ...(opts.metrics
        ? {
            metricReaders: [
              new PeriodicExportingMetricReader({
                exporter: new OTLPMetricExporter({ url: `${opts.endpoint}/v1/metrics`, timeoutMillis: EXPORT_TIMEOUT_MS }),
                exportIntervalMillis: 60_000,
              }),
            ],
          }
        : {}),
      instrumentations: opts.autoInstrument ? nodeInstrumentations(opts) : [],
    });
    sdk.start();

    flush = async () => {
      await spanProcessor.forceFlush();
      await logProcessor?.forceFlush();
    };
    stop = () => sdk.shutdown();
  } else {
    const provider = new BasicTracerProvider({ resource, sampler, spanProcessors: [spanProcessor] });
    context.setGlobalContextManager(new AsyncLocalStorageContextManager().enable());
    propagation.setGlobalPropagator(
      new CompositePropagator({ propagators: [new W3CTraceContextPropagator(), new W3CBaggagePropagator()] }),
    );
    trace.setGlobalTracerProvider(provider);

    let loggerProvider: LoggerProvider | undefined;
    if (logProcessor) {
      loggerProvider = new LoggerProvider({ resource, processors: [logProcessor] });
      logs.setGlobalLoggerProvider(loggerProvider);
    }

    flush = async () => {
      await provider.forceFlush();
      await loggerProvider?.forceFlush();
    };
    stop = async () => {
      await provider.shutdown();
      await loggerProvider?.shutdown();
      trace.disable();
      context.disable();
      propagation.disable();
      logs.disable();
    };
  }

  if (opts.captureProcessErrors) {
    uninstall = installProcessHandlers(() => {
      void flush().catch(() => {});
    });
  }

  const handle: FalakHandle = {
    options: opts,
    forceFlush: () => bounded(flush(), EXPORT_TIMEOUT_MS + 500),
    shutdown: async () => {
      uninstall();
      if (active === handle) active = undefined;
      await bounded(stop(), EXPORT_TIMEOUT_MS + 500);
    },
  };

  active = handle;
  return handle;
}

/** The running handle, if started. */
export function current(): FalakHandle | undefined {
  return active;
}

export async function shutdown(): Promise<void> {
  await active?.shutdown();
}

export async function forceFlush(): Promise<void> {
  await active?.forceFlush();
}
