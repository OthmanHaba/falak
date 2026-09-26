import { echo } from '@/lib/echo';
import { useEffect, useRef } from 'react';

/**
 * Subscribe to a private channel and listen for one or more broadcast events while mounted.
 * Event names are the broadcastAs() names (a leading "." is added automatically).
 */
export function useEchoChannel<T>(channel: string | null, events: string[], handler: (event: string, payload: T) => void): boolean {
    const handlerRef = useRef(handler);
    handlerRef.current = handler;
    const client = echo();
    const eventsKey = events.join('|');

    useEffect(() => {
        if (!client || !channel) {
            return;
        }

        const subscription = client.private(channel);
        const names = eventsKey.split('|');

        names.forEach((name) => subscription.listen(`.${name}`, (payload: T) => handlerRef.current(name, payload)));

        return () => {
            names.forEach((name) => subscription.stopListening(`.${name}`));
            client.leave(channel);
        };
    }, [client, channel, eventsKey]);

    return client !== null;
}
