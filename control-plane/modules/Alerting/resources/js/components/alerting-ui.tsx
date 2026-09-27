/** JSON request with session cookie + CSRF token (for endpoints outside Inertia visits). */
export async function jsonRequest<T>(method: 'GET' | 'POST', url: string): Promise<{ ok: boolean; status: number; body: T | null }> {
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf },
    });

    let body: T | null = null;
    try {
        body = (await response.json()) as T;
    } catch {
        body = null;
    }

    return { ok: response.ok, status: response.status, body };
}
