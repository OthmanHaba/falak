export { start, shutdown, forceFlush, current, type FalakHandle, type StartOverrides } from './sdk.js';
export { resolveOptions, DEFAULT_ENDPOINT, DEFAULT_REDACT_KEYS, type FalakOptions, type ResolvedOptions, type RedactCallback } from './config.js';
export { recordException, installProcessHandlers, type RecordExceptionOptions } from './exceptions.js';
export { withFalakRequest, setUser, type FetchHandler, type WithFalakRequestOptions } from './fetch.js';
export { FalakSpanProcessor, mapSpan, classify, type FalakEventType } from './mapping.js';
export { Redactor, REDACTED } from './redact.js';
export { falakResourceAttributes } from './resource.js';
export { nodeInstrumentations, redisCacheHook } from './instrumentations.js';
export { VERSION } from './version.js';
