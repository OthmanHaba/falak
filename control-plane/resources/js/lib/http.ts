/**
 * fetch() helpers for JSON endpoints the canvas panel talks to (session auth + CSRF).
 *
 * Mutation endpoints that answer an Inertia-style `back()` redirect are treated as success without following the
 * redirect (the panel re-fetches its own JSON instead of reloading the page).
 */
export class HttpError extends Error {
    constructor(
        message: string,
        public status: number,
        /** Laravel validation errors (422), first message per field. */
        public errors: Record<string, string> = {},
    ) {
        super(message);
    }
}

function xsrfToken(): string {
    return decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export async function requestJson<T = unknown>(url: string, method: HttpMethod = 'GET', body?: unknown, init: { signal?: AbortSignal } = {}): Promise<T> {
    const xsrf = xsrfToken();
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        redirect: 'manual',
        signal: init.signal,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
            ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    // `return back()` style endpoints: the action succeeded.
    if (response.type === 'opaqueredirect' || response.status === 204) {
        return null as T;
    }

    const payload = (await response.json().catch(() => ({}))) as T & { message?: string; errors?: Record<string, string[]> };

    if (!response.ok) {
        const errors = Object.fromEntries(Object.entries(payload.errors ?? {}).map(([key, messages]) => [key, messages[0] ?? '']));
        const first = Object.values(errors)[0];

        throw new HttpError(first || payload.message || `Request failed (HTTP ${response.status})`, response.status, errors);
    }

    return payload;
}

export function errorMessage(error: unknown, fallback = 'Something went wrong'): string {
    return error instanceof Error && error.message ? error.message : fallback;
}
