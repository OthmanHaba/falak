import { type FlashMessages, type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from './toast';

type FlashKind = 'success' | 'error' | 'warning' | 'info';

/**
 * Laravel flash messages (`back()->with('success', ...)`) shared by HandleInertiaRequests as `flash`.
 * `status` values that are machine codes (e.g. "verification-link-sent") are left to the pages that read them.
 */
export function toastsFrom(flash: FlashMessages | undefined): { kind: FlashKind; message: string }[] {
    if (!flash) return [];

    const toasts: { kind: FlashKind; message: string }[] = [];

    if (flash.success) toasts.push({ kind: 'success', message: flash.success });
    if (flash.error) toasts.push({ kind: 'error', message: flash.error });
    if (flash.warning) toasts.push({ kind: 'warning', message: flash.warning });
    if (flash.status && /\s/.test(flash.status)) toasts.push({ kind: 'info', message: flash.status });

    return toasts;
}

/** Shows flash messages as toasts, once per visit (partial reloads keep the same flash object). */
export function useFlashToasts(): void {
    const { flash } = usePage<SharedData>().props;
    const seen = useRef<FlashMessages | undefined>(undefined);

    useEffect(() => {
        if (flash === seen.current) return;
        seen.current = flash;
        toastsFrom(flash).forEach((item) => toast[item.kind](item.message));
    }, [flash]);
}
