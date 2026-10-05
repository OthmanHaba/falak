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
 * (no VITE_REVERB_APP_KEY and no runtime `falak-reverb-key` meta tag), in which case pages fall back to polling.
 * Without build-time VITE_REVERB_* settings (prebuilt production images) the websocket goes to the page's own
 * host, where the edge proxies /app and /apps to Reverb.
 */
export function echo(): Echo<'reverb'> | null {
    if (instance !== undefined) {
        return instance;
    }

    if (typeof window === 'undefined') {
        instance = null;

        return instance;
    }

    const key =
        (import.meta.env.VITE_REVERB_APP_KEY as string | undefined) ||
        document.querySelector<HTMLMetaElement>('meta[name="falak-reverb-key"]')?.content;

    if (!key) {
        instance = null;

        return instance;
    }

    window.Pusher = Pusher;

    const scheme = (import.meta.env.VITE_REVERB_SCHEME as string | undefined) ?? (window.location.protocol === 'http:' ? 'http' : 'https');
    const port = Number(import.meta.env.VITE_REVERB_PORT ?? (window.location.port || (scheme === 'https' ? 443 : 80)));

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
