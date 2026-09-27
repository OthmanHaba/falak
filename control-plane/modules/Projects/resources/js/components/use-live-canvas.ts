import { echo } from '@/lib/echo';
import { requestJson } from '@/lib/http';
import { type Canvas } from '@/types';
import { useCallback, useEffect, useRef, useState } from 'react';

const BUSY = ['deploying', 'building', 'queued', 'provisioning'];

/**
 * The canvas read model, kept live (§1.3): polls GET …/canvas (fast while something is deploying), and re-fetches
 * right away when a site's deployment changes (Reverb `deployments.site.{id}`) when broadcasting is configured.
 */
export function useLiveCanvas(url: string, initial: Canvas) {
    const [canvas, setCanvas] = useState<Canvas>(initial);
    const inflight = useRef<Promise<void> | null>(null);

    // A fresh page visit (other environment, partial reload) replaces the local copy.
    useEffect(() => setCanvas(initial), [initial]);

    const refresh = useCallback(() => {
        if (inflight.current) return inflight.current;
        inflight.current = requestJson<Canvas>(url)
            .then((next) => setCanvas(next))
            .catch(() => undefined)
            .finally(() => {
                inflight.current = null;
            });

        return inflight.current;
    }, [url]);

    const busy = canvas.services.some((service) => BUSY.includes(service.status));
    const client = echo();

    useEffect(() => {
        const timer = window.setInterval(
            () => {
                if (document.visibilityState === 'visible') void refresh();
            },
            busy ? 3000 : client ? 30000 : 15000,
        );

        return () => window.clearInterval(timer);
    }, [busy, client, refresh]);

    const siteKey = canvas.services
        .filter((service) => service.kind === 'site')
        .map((service) => service.ref_id)
        .sort()
        .join(',');

    useEffect(() => {
        if (!client || !siteKey) return;
        const channels = siteKey.split(',').map((id) => `deployments.site.${id}`);
        channels.forEach((name) => client.private(name).listen('.deployment.updated', () => void refresh()));

        return () => channels.forEach((name) => client.leave(name));
    }, [client, siteKey, refresh]);

    return { canvas, setCanvas, refresh };
}
