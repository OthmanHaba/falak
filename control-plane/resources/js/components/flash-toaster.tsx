import { cn } from '@/lib/utils';
import { type FlashMessages, type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Info, X, XCircle } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type ToastKind = 'success' | 'error' | 'warning' | 'info';

interface Toast {
    id: number;
    kind: ToastKind;
    message: string;
}

const ICONS = { success: CheckCircle2, error: XCircle, warning: AlertTriangle, info: Info } as const;

const STYLES: Record<ToastKind, string> = {
    success: 'border-emerald-500/40 [&>svg]:text-emerald-600',
    error: 'border-destructive/50 [&>svg]:text-destructive',
    warning: 'border-amber-500/50 [&>svg]:text-amber-600',
    info: '[&>svg]:text-muted-foreground',
};

/**
 * Laravel flash messages (`back()->with('success', ...)`) shared by HandleInertiaRequests as `flash`.
 * `status` values that are machine codes (e.g. "verification-link-sent") are left to the pages that read them.
 */
export function toastsFrom(flash: FlashMessages | undefined): { kind: ToastKind; message: string }[] {
    if (!flash) return [];

    const toasts: { kind: ToastKind; message: string }[] = [];

    if (flash.success) toasts.push({ kind: 'success', message: flash.success });
    if (flash.error) toasts.push({ kind: 'error', message: flash.error });
    if (flash.warning) toasts.push({ kind: 'warning', message: flash.warning });
    if (flash.status && /\s/.test(flash.status)) toasts.push({ kind: 'info', message: flash.status });

    return toasts;
}

export function FlashToaster() {
    const { flash } = usePage<SharedData>().props;
    const [toasts, setToasts] = useState<Toast[]>([]);
    const nextId = useRef(0);
    const seen = useRef<FlashMessages | undefined>(undefined);

    // A new flash object arrives with every full visit; partial reloads keep the previous one.
    useEffect(() => {
        if (flash === seen.current) return;
        seen.current = flash;

        const incoming = toastsFrom(flash).map((toast) => ({ ...toast, id: nextId.current++ }));
        if (incoming.length === 0) return;

        setToasts((current) => [...current, ...incoming].slice(-5));

        const timers = incoming.map((toast) =>
            window.setTimeout(() => setToasts((current) => current.filter((t) => t.id !== toast.id)), toast.kind === 'error' ? 10000 : 5000),
        );

        return () => timers.forEach((timer) => window.clearTimeout(timer));
    }, [flash]);

    const dismiss = (id: number) => setToasts((current) => current.filter((toast) => toast.id !== id));

    return (
        <div aria-live="polite" className="pointer-events-none fixed right-4 bottom-4 z-50 flex w-full max-w-sm flex-col gap-2">
            {toasts.map((toast) => {
                const Icon = ICONS[toast.kind];

                return (
                    <div
                        key={toast.id}
                        role={toast.kind === 'error' ? 'alert' : 'status'}
                        className={cn(
                            'bg-background text-foreground pointer-events-auto relative flex items-start gap-3 rounded-lg border p-3 pr-9 text-sm shadow-lg',
                            STYLES[toast.kind],
                        )}
                    >
                        <Icon className="mt-0.5 size-4 shrink-0" />
                        <p className="min-w-0 break-words">{toast.message}</p>
                        <button
                            type="button"
                            onClick={() => dismiss(toast.id)}
                            className="text-muted-foreground hover:text-foreground absolute top-2.5 right-2.5 rounded-sm"
                            aria-label="Dismiss"
                        >
                            <X className="size-4" />
                        </button>
                    </div>
                );
            })}
        </div>
    );
}
