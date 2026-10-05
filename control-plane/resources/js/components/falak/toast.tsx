import { cn } from '@/lib/utils';
import { AlertTriangle, CheckCircle2, Info, LoaderCircle, X, XCircle } from 'lucide-react';
import { useSyncExternalStore, type ReactNode } from 'react';

export type ToastKind = 'success' | 'error' | 'warning' | 'info' | 'loading';

export interface ToastItem {
    id: number;
    kind: ToastKind;
    message: ReactNode;
    description?: ReactNode;
}

type Listener = () => void;

let items: ToastItem[] = [];
let nextId = 1;
const listeners = new Set<Listener>();
const timers = new Map<number, number>();

const emit = () => listeners.forEach((listener) => listener());

function dismiss(id: number): void {
    items = items.filter((item) => item.id !== id);
    const timer = timers.get(id);
    if (timer) window.clearTimeout(timer);
    timers.delete(id);
    emit();
}

function schedule(id: number, kind: ToastKind): void {
    if (kind === 'loading' || typeof window === 'undefined') return;
    timers.set(
        id,
        window.setTimeout(() => dismiss(id), kind === 'error' ? 10_000 : 5_000),
    );
}

function show(kind: ToastKind, message: ReactNode, description?: ReactNode): number {
    const id = nextId++;
    items = [...items, { id, kind, message, description }].slice(-5);
    schedule(id, kind);
    emit();

    return id;
}

function update(id: number, kind: ToastKind, message: ReactNode, description?: ReactNode): void {
    items = items.map((item) => (item.id === id ? { ...item, kind, message, description } : item));
    schedule(id, kind);
    emit();
}

/**
 * Imperative toasts: `toast.success('Saved')`, `toast.error(...)`, or
 * `toast.promise(save(), { loading: 'Saving…', success: 'Saved', error: 'Could not save' })`.
 */
export const toast = Object.assign((message: ReactNode, description?: ReactNode) => show('info', message, description), {
    success: (message: ReactNode, description?: ReactNode) => show('success', message, description),
    error: (message: ReactNode, description?: ReactNode) => show('error', message, description),
    warning: (message: ReactNode, description?: ReactNode) => show('warning', message, description),
    info: (message: ReactNode, description?: ReactNode) => show('info', message, description),
    dismiss,
    async promise<T>(
        promise: Promise<T>,
        messages: { loading: ReactNode; success: ReactNode | ((value: T) => ReactNode); error: ReactNode | ((error: unknown) => ReactNode) },
    ): Promise<T> {
        const id = show('loading', messages.loading);
        try {
            const value = await promise;
            update(id, 'success', typeof messages.success === 'function' ? messages.success(value) : messages.success);

            return value;
        } catch (error) {
            update(id, 'error', typeof messages.error === 'function' ? messages.error(error) : messages.error);
            throw error;
        }
    },
});

function subscribe(listener: Listener): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

const EMPTY: ToastItem[] = [];

const ICONS = { success: CheckCircle2, error: XCircle, warning: AlertTriangle, info: Info, loading: LoaderCircle } as const;
const ICON_TONE: Record<ToastKind, string> = {
    success: 'text-success',
    error: 'text-danger',
    warning: 'text-warning',
    info: 'text-info',
    loading: 'text-fg-muted animate-spin',
};

/** Renders the toast stack (bottom-right). Mounted once by AppShell / auth layout. */
export function Toaster() {
    const toasts = useSyncExternalStore(
        subscribe,
        () => items,
        () => EMPTY,
    );

    return (
        <div aria-live="polite" className="pointer-events-none fixed right-4 bottom-4 z-[60] flex w-[calc(100vw-2rem)] max-w-sm flex-col gap-2">
            {toasts.map((item) => {
                const Icon = ICONS[item.kind];

                return (
                    <div
                        key={item.id}
                        role={item.kind === 'error' ? 'alert' : 'status'}
                        className="animate-dialog-in border-border bg-surface-1 text-fg shadow-panel pointer-events-auto relative flex items-start gap-2.5 rounded-lg border p-3 pr-9 text-sm"
                    >
                        <Icon className={cn('mt-0.5 size-4 shrink-0', ICON_TONE[item.kind])} aria-hidden />
                        <div className="grid min-w-0 gap-0.5">
                            <p className="break-words">{item.message}</p>
                            {item.description && <p className="text-fg-muted text-xs">{item.description}</p>}
                        </div>
                        <button
                            type="button"
                            onClick={() => dismiss(item.id)}
                            className="text-fg-faint hover:text-fg absolute top-2.5 right-2.5 rounded-sm p-0.5"
                            aria-label="Dismiss"
                        >
                            <X className="size-3.5" />
                        </button>
                    </div>
                );
            })}
        </div>
    );
}
