import { requestJson } from '@/lib/http';
import { useCallback, useEffect, useRef, useState } from 'react';

interface JsonState<T> {
    data: T | null;
    error: string | null;
    loading: boolean;
    /** Re-fetch now (keeps the current data while loading). */
    reload: () => Promise<void>;
    /** Replace the data locally (optimistic updates). */
    setData: (updater: T | ((current: T | null) => T)) => void;
}

// Shared across mounts so switching panel tabs back and forth renders instantly from the last response.
const cache = new Map<string, unknown>();
// Concurrent mounts of the same URL (e.g. several settings sections of one panel) share one request.
const inflight = new Map<string, Promise<unknown>>();

function fetchShared(url: string): Promise<unknown> {
    const pending = inflight.get(url);
    if (pending) return pending;
    const request = requestJson<unknown>(url).finally(() => inflight.delete(url));
    inflight.set(url, request);

    return request;
}

/**
 * GET a JSON endpoint (`{data: T}` envelope unwrapped), optionally polling. `url === null` pauses the hook.
 * Polling pauses while the document is hidden.
 */
export function useJson<T>(url: string | null, options: { interval?: number | false; unwrap?: boolean } = {}): JsonState<T> {
    const { interval = false, unwrap = true } = options;
    const [data, setDataState] = useState<T | null>(() => (url ? ((cache.get(url) as T | undefined) ?? null) : null));
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(url !== null && !cache.has(url));
    const current = useRef(url);
    current.current = url;

    const reload = useCallback(async () => {
        if (!url) return;
        setLoading(true);
        try {
            const body = (await fetchShared(url)) as { data: T } | T;
            const value = (unwrap ? (body as { data: T }).data : body) as T;
            cache.set(url, value);
            if (current.current === url) {
                setDataState(value);
                setError(null);
            }
        } catch (e) {
            if (current.current === url) setError(e instanceof Error ? e.message : 'Could not load');
        } finally {
            if (current.current === url) setLoading(false);
        }
    }, [url, unwrap]);

    useEffect(() => {
        setDataState(url ? ((cache.get(url) as T | undefined) ?? null) : null);
        void reload();
    }, [url, reload]);

    useEffect(() => {
        if (!url || !interval) return;
        const timer = window.setInterval(() => {
            if (document.visibilityState === 'visible') void reload();
        }, interval);

        return () => window.clearInterval(timer);
    }, [url, interval, reload]);

    const setData = useCallback(
        (updater: T | ((current: T | null) => T)) => {
            setDataState((previous) => {
                const next = typeof updater === 'function' ? (updater as (current: T | null) => T)(previous) : updater;
                if (url) cache.set(url, next);

                return next;
            });
        },
        [url],
    );

    return { data, error, loading, reload, setData };
}
