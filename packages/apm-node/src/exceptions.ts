import { SpanStatusCode, trace, type Span } from '@opentelemetry/api';
import { SCOPE } from './version.js';

export interface RecordExceptionOptions {
  /** false = the error escaped application code (span becomes ERROR). Default true. */
  handled?: boolean;
  /** Target span. Default: the active span. */
  span?: Span;
}

function describe(error: unknown): { type: string; message: string; stack: string | undefined } {
  if (error instanceof Error) {
    return { type: error.name || error.constructor?.name || 'Error', message: error.message, stack: error.stack };
  }
  return { type: typeof error, message: String(error), stack: undefined };
}

/**
 * Record an OTel `exception` event with kiln.exception.handled. Returns false when there
 * is no span to attach it to.
 */
export function recordException(error: unknown, options: RecordExceptionOptions = {}): boolean {
  const span = options.span ?? trace.getActiveSpan();
  if (!span || !span.isRecording()) return false;

  const handled = options.handled ?? true;
  const { type, message, stack } = describe(error);

  span.addEvent('exception', {
    'exception.type': type,
    'exception.message': message,
    ...(stack ? { 'exception.stacktrace': stack } : {}),
    'exception.escaped': !handled,
    'kiln.exception.handled': handled,
  });

  if (!handled) {
    span.setStatus({ code: SpanStatusCode.ERROR, message });
    span.setAttribute('error.type', type);
  }

  return true;
}

type ProcessLike = {
  on(event: string, listener: (...args: unknown[]) => void): unknown;
  off?(event: string, listener: (...args: unknown[]) => void): unknown;
  listenerCount(event: string): number;
  nextTick?(fn: () => void): void;
};

/**
 * Records uncaught exceptions / unhandled rejections as handled=false. Uses the non-intrusive
 * `uncaughtExceptionMonitor`; for rejections the default crash behaviour is preserved when no
 * other listener exists. Returns an uninstall function.
 */
export function installProcessHandlers(onRecorded?: () => void): () => void {
  const proc = (globalThis as { process?: ProcessLike }).process;
  if (!proc || typeof proc.on !== 'function') return () => {};

  const record = (error: unknown, kind: string) => {
    try {
      const active = trace.getActiveSpan();
      if (active?.isRecording()) {
        recordException(error, { handled: false, span: active });
      } else {
        const span = trace.getTracer(SCOPE).startSpan(kind);
        recordException(error, { handled: false, span });
        span.end();
      }
      onRecorded?.();
    } catch {
      // never throw from a crash handler
    }
  };

  const onException = (error: unknown) => record(error, 'process.uncaught_exception');
  const onRejection = (reason: unknown) => {
    record(reason, 'process.unhandled_rejection');
    // Our listener suppresses Node's default "throw" mode: restore it if we're alone.
    if (proc.listenerCount('unhandledRejection') === 1 && proc.nextTick) {
      proc.nextTick(() => {
        throw reason;
      });
    }
  };

  proc.on('uncaughtExceptionMonitor', onException);
  proc.on('unhandledRejection', onRejection);

  return () => {
    proc.off?.('uncaughtExceptionMonitor', onException);
    proc.off?.('unhandledRejection', onRejection);
  };
}
