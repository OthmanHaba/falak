import { useCallback, useEffect, useSyncExternalStore } from 'react';

export type Appearance = 'dark' | 'light' | 'system';
export type ResolvedAppearance = 'dark' | 'light';

/**
 * Theme preference: dark (default) · light · system.
 *
 * Persisted in the unencrypted `appearance` cookie (1 year) so resources/views/app.blade.php renders the right
 * `dark`/`light` class on <html> for the first paint; this module keeps it in sync on the client.
 */
const COOKIE = 'appearance';
const EVENT = 'falak:appearance';

const isAppearance = (value: unknown): value is Appearance => value === 'dark' || value === 'light' || value === 'system';

function readCookie(): Appearance {
    if (typeof document === 'undefined') return 'dark';

    const match = document.cookie.split('; ').find((part) => part.startsWith(`${COOKIE}=`));
    const value = match ? decodeURIComponent(match.slice(COOKIE.length + 1)) : null;

    return isAppearance(value) ? value : 'dark';
}

function writeCookie(value: Appearance): void {
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${COOKIE}=${value}; Path=/; Max-Age=31536000; SameSite=Lax${secure}`;
}

const systemQuery = () => (typeof window === 'undefined' ? null : window.matchMedia('(prefers-color-scheme: dark)'));

export function resolveAppearance(appearance: Appearance): ResolvedAppearance {
    if (appearance !== 'system') return appearance;

    return systemQuery()?.matches === false ? 'light' : 'dark';
}

function applyTheme(appearance: Appearance): void {
    const resolved = resolveAppearance(appearance);
    const root = document.documentElement;

    root.classList.toggle('dark', resolved === 'dark');
    root.classList.toggle('light', resolved === 'light');
    root.dataset.appearance = appearance;
}

export function setAppearance(appearance: Appearance): void {
    writeCookie(appearance);
    applyTheme(appearance);
    window.dispatchEvent(new Event(EVENT));
}

let initialized = false;

/** Called once from app.tsx: re-applies the cookie value and follows OS changes while on "system". */
export function initializeTheme(): void {
    if (initialized || typeof window === 'undefined') return;
    initialized = true;

    applyTheme(readCookie());
    systemQuery()?.addEventListener('change', () => {
        if (readCookie() === 'system') {
            applyTheme('system');
            window.dispatchEvent(new Event(EVENT));
        }
    });
}

function subscribe(callback: () => void): () => void {
    window.addEventListener(EVENT, callback);

    return () => window.removeEventListener(EVENT, callback);
}

export function useAppearance(): { appearance: Appearance; resolved: ResolvedAppearance; updateAppearance: (value: Appearance) => void } {
    const appearance = useSyncExternalStore(subscribe, readCookie, () => 'dark' as Appearance);
    const resolved = useSyncExternalStore(
        subscribe,
        () => resolveAppearance(readCookie()),
        () => 'dark' as ResolvedAppearance,
    );

    const updateAppearance = useCallback((value: Appearance) => setAppearance(value), []);

    useEffect(() => initializeTheme(), []);

    return { appearance, resolved, updateAppearance };
}
