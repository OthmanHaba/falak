import type { Attributes, AttributeValue } from '@opentelemetry/api';

export const REDACTED = '[redacted]';

/** Attributes whose *values* are handled by dedicated rules rather than the key denylist. */
const EXEMPT = new Set(['falak.event.type', 'db.query.text', 'db.statement', 'falak.cache.key']);
const URL_KEYS = new Set(['url.full', 'http.url']);
const QUERY_KEYS = new Set(['url.query']);
const TARGET_KEYS = new Set(['http.target']);

export class Redactor {
  constructor(
    private readonly keys: string[],
    private readonly queryLiterals = true,
  ) {}

  isSensitive(key: string): boolean {
    const k = key.toLowerCase();
    return this.keys.some((needle) => k.includes(needle));
  }

  queryString(query: string): string {
    return query
      .split('&')
      .map((pair) => {
        const name = pair.split('=', 1)[0] ?? '';
        let decoded = name;
        try {
          decoded = decodeURIComponent(name);
        } catch {
          // keep raw
        }
        return this.isSensitive(decoded) ? `${name}=${encodeURIComponent(REDACTED)}` : pair;
      })
      .join('&');
  }

  url(value: string): string {
    const q = value.indexOf('?');
    let out = value;

    // user:password@host
    out = out.replace(/^([a-z][a-z0-9+.-]*:\/\/[^:/?#@]*):([^@/?#]*)@/i, (_m, user: string) => `${user}:${REDACTED}@`);

    if (q === -1) return out;
    const qi = out.indexOf('?');
    const hash = out.indexOf('#', qi);
    const query = out.slice(qi + 1, hash === -1 ? undefined : hash);
    const fragment = hash === -1 ? '' : out.slice(hash);

    return `${out.slice(0, qi)}?${this.queryString(query)}${fragment}`;
  }

  sql(value: string): string {
    return this.queryLiterals ? value.replace(/'(?:[^'\\]|\\.|'')*'/gs, '?') : value;
  }

  value(key: string, value: AttributeValue | undefined): AttributeValue | undefined {
    if (typeof value !== 'string') {
      return !EXEMPT.has(key) && this.isSensitive(key) && value !== undefined ? REDACTED : value;
    }
    if (URL_KEYS.has(key)) return this.url(value);
    if (QUERY_KEYS.has(key)) return this.queryString(value.replace(/^\?/, ''));
    if (TARGET_KEYS.has(key)) {
      const i = value.indexOf('?');
      return i === -1 ? value : `${value.slice(0, i)}?${this.queryString(value.slice(i + 1))}`;
    }
    if (key === 'db.query.text' || key === 'db.statement') return this.sql(value);
    if (key === 'falak.cache.key') return this.isSensitive(value) ? REDACTED : value;
    if (EXEMPT.has(key)) return value;
    return this.isSensitive(key) ? REDACTED : value;
  }

  /** Redact in place. */
  attributes(attributes: Attributes): Attributes {
    for (const key of Object.keys(attributes)) {
      const next = this.value(key, attributes[key]);
      if (next !== attributes[key]) attributes[key] = next;
    }
    return attributes;
  }
}
