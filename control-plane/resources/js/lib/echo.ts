import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
    }
}

let instance: Echo<'reverb'> | null | undefined;

/**
 * Lazily-created Echo client for Reverb. Returns null when broadcasting is not configured
 * (no VITE_REVERB_APP_KEY), in which case pages fall back to polling.
 */
export function echo(): Echo<'reverb'> | null {
    if (instance !== undefined) {
        return instance;
    }

    const key = import.meta.env.VITE_REVERB_APP_KEY as string | undefined;

    if (typeof window === 'undefined' || !key) {
        instance = null;

        return instance;
    }

    window.Pusher = Pusher;

    const scheme = (import.meta.env.VITE_REVERB_SCHEME as string | undefined) ?? 'https';
    const port = Number(import.meta.env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 80));

    // Private channels authorize via POST /broadcasting/auth (session + CSRF).
    const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

    instance = new Echo({
        broadcaster: 'reverb',
        csrfToken,
        auth: { headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' } },
        key,
        wsHost: (import.meta.env.VITE_REVERB_HOST as string | undefined) ?? window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    return instance;
}
