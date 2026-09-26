import { useEffect, useState } from 'react';

export interface Region {
    id: string;
    name: string;
    country: string | null;
    available: boolean;
}

export interface Size {
    id: string;
    name: string;
    cpus: number;
    memory_mb: number;
    disk_gb: number;
    price_monthly: number | null;
    regions: string[];
    arch: string;
}

export interface Image {
    id: string;
    name: string;
    distribution: string;
    version: string | null;
    arch: string | null;
}

export interface CatalogState<T> {
    items: T[];
    loading: boolean;
    error: string | null;
}

/**
 * Loads a provider catalog (regions / sizes / images) from the Providers module's JSON endpoints.
 */
export function useCatalog<T>(url: string | null): CatalogState<T> {
    const [state, setState] = useState<CatalogState<T>>({ items: [], loading: false, error: null });

    useEffect(() => {
        if (!url) {
            setState({ items: [], loading: false, error: null });

            return;
        }

        const controller = new AbortController();
        setState({ items: [], loading: true, error: null });

        fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller.signal,
        })
            .then(async (response) => {
                const body = (await response.json().catch(() => ({}))) as { data?: T[]; message?: string };

                if (!response.ok) {
                    throw new Error(body.message ?? `HTTP ${response.status}`);
                }

                setState({ items: body.data ?? [], loading: false, error: null });
            })
            .catch((error: unknown) => {
                if (controller.signal.aborted) return;
                setState({ items: [], loading: false, error: error instanceof Error ? error.message : 'Request failed' });
            });

        return () => controller.abort();
    }, [url]);

    return state;
}
