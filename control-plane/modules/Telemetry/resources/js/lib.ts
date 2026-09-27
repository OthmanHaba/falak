export class HttpError extends Error {
    constructor(
        public status: number,
        message: string,
    ) {
        super(message);
    }
}

/** GET a JSON endpoint of this app (session auth), surfacing `{ message }` bodies as errors. */
export async function getJson<T>(url: string, signal?: AbortSignal): Promise<T> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        signal,
    });

    if (!response.ok) {
        let message = `HTTP ${response.status}`;

        try {
            const body = (await response.json()) as { message?: string; errors?: Record<string, string[]> };
            const firstError = body.errors ? Object.values(body.errors)[0]?.[0] : undefined;
            message = firstError ?? body.message ?? message;
        } catch {
            // non-JSON error body
        }

        throw new HttpError(response.status, message);
    }

    return (await response.json()) as T;
}

export function queryString(params: Record<string, string | number | boolean | null | undefined>): string {
    const search = new URLSearchParams();

    Object.entries(params).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '' && value !== false) {
            search.set(key, value === true ? '1' : String(value));
        }
    });

    const text = search.toString();

    return text ? `?${text}` : '';
}

export function formatDuration(ms: number): string {
    if (ms === 0) return '0ms';
    if (ms < 1) return `${Math.round(ms * 1000)}µs`;
    if (ms < 1000) return `${ms < 10 ? ms.toFixed(1) : Math.round(ms)}ms`;
    if (ms < 60_000) return `${(ms / 1000).toFixed(2)}s`;

    return `${(ms / 60_000).toFixed(1)}m`;
}

export function formatBytes(bytes: number): string {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;

    while (Math.abs(value) >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(value < 10 && unit > 0 ? 1 : 0)} ${units[unit]}`;
}
