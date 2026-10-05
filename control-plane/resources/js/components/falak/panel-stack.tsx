import { cn } from '@/lib/utils';
import { X } from 'lucide-react';
import { useCallback, useEffect, useLayoutEffect, useRef, useState, type CSSProperties, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { IconButton } from './button';

/**
 * One floating panel of a {@link PanelStack}. `key` is its identity: a new key animates in, a key that disappears
 * animates out.
 */
export interface PanelLayer {
    key: string;
    /** Accessible name of the dialog. */
    label: string;
    content: ReactNode;
}

export interface PanelStackProps {
    layers: PanelLayer[];
    /** Close the layer at `index` and every layer above it (Esc / ✕ close the top one; clicking a receded layer closes what covers it). */
    onDismiss: (index: number) => void;
    /** Drag the left edge of the top layer to resize (≥ 1024px); the width is remembered under this key. */
    resizeKey?: string;
    /** The top layer spans the whole canvas (pop-out view of a log). */
    maximized?: boolean;
}

const MIN_WIDTH = 520;
const EXIT = { duration: 180, easing: 'cubic-bezier(0.4, 0, 1, 1)' };
const FOCUSABLE =
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

function prefersReducedMotion(): boolean {
    return typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
}

function storedWidth(key: string | undefined): number | null {
    if (!key || typeof window === 'undefined') return null;
    try {
        const value = Number(window.localStorage.getItem(`falak:panel-width:${key}`));

        return Number.isFinite(value) && value >= MIN_WIDTH ? value : null;
    } catch {
        return null;
    }
}

/** A Radix layer (menu, select, dialog, popover) or a text field owns Escape before the stack does. */
function escapeHandledElsewhere(event: KeyboardEvent): boolean {
    if (event.defaultPrevented) return true;
    if (document.querySelector('[data-radix-popper-content-wrapper], [role="alertdialog"], [role="dialog"]:not([data-falak-panel])')) return true;

    return false;
}

/** Left-edge drag handle of the top layer. */
function ResizeHandle({ onResize, onDone }: { onResize: (width: number) => void; onDone: () => void }) {
    return (
        <div
            role="separator"
            aria-orientation="vertical"
            aria-label="Resize panel"
            className="hover:bg-primary/40 absolute inset-y-4 -left-1 z-40 hidden w-2 cursor-col-resize rounded-full transition-colors lg:block"
            onPointerDown={(event) => {
                event.preventDefault();
                const target = event.currentTarget;
                target.setPointerCapture(event.pointerId);
                const move = (e: PointerEvent) =>
                    onResize(Math.min(Math.max(window.innerWidth - e.clientX - 12, MIN_WIDTH), window.innerWidth - 120));
                const up = () => {
                    target.removeEventListener('pointermove', move);
                    target.removeEventListener('pointerup', up);
                    onDone();
                };
                target.addEventListener('pointermove', move);
                target.addEventListener('pointerup', up);
            }}
        />
    );
}

interface Rendered {
    layer: PanelLayer;
    leaving: boolean;
}

/**
 * Floating, stacked panels over the canvas (docs/UI_DESIGN.md §5, §5.5). Each layer is a non-modal dialog inset from
 * the canvas edges; the canvas stays visible and interactive on the left. Pushing a layer slides it in from the right
 * while the one below recedes (shifted left, scaled down, veiled) so its edge still peeks out; popping reverses it.
 *
 * Keyboard: Esc closes the top layer (a focused text field is blurred first), Tab cycles inside the top layer, focus
 * moves into a new layer and back to where it was opened from when it closes. Motion respects prefers-reduced-motion.
 */
export function PanelStack({ layers, onDismiss, resizeKey, maximized = false }: PanelStackProps) {
    const [rendered, setRendered] = useState<Rendered[]>(() => layers.map((layer) => ({ layer, leaving: false })));
    const elements = useRef(new Map<string, HTMLElement>());
    const openers = useRef(new Map<string, Element | null>());
    const [width, setWidth] = useState<number | null>(() => storedWidth(resizeKey));
    const [mounted, setMounted] = useState(false);

    useEffect(() => setMounted(true), []);

    // Reconcile: new layers enter, missing layers leave (kept rendered until their exit animation ends).
    useLayoutEffect(() => {
        setRendered((current) => {
            const next: Rendered[] = layers.map((layer) => ({ layer, leaving: false }));
            const keys = new Set(layers.map((layer) => layer.key));
            current.forEach((item, index) => {
                if (!keys.has(item.layer.key)) next.splice(Math.min(index, next.length), 0, { layer: item.layer, leaving: true });
            });

            return next;
        });
    }, [layers]);

    // Run exit animations for leaving layers, then drop them.
    useEffect(() => {
        rendered
            .filter((item) => item.leaving)
            .forEach((item) => {
                const element = elements.current.get(item.layer.key);
                if (!element || element.dataset.exiting === 'true') return;
                element.dataset.exiting = 'true';
                const done = () => setRendered((current) => current.filter((entry) => entry.layer.key !== item.layer.key || !entry.leaving));
                if (prefersReducedMotion() || typeof element.animate !== 'function') {
                    done();

                    return;
                }
                const from = getComputedStyle(element).transform;
                element
                    .animate(
                        [
                            { transform: from === 'none' ? 'translateX(0)' : from, opacity: 1 },
                            { transform: 'translateX(28px) scale(0.985)', opacity: 0 },
                        ],
                        { ...EXIT, fill: 'forwards' },
                    )
                    .finished.then(done, done);
            });
    }, [rendered]);

    const active = rendered.filter((item) => !item.leaving);
    const topKey = active[active.length - 1]?.layer.key;

    // Focus: into the new top layer; back to the opener when it closes.
    const previousTop = useRef<string | undefined>(undefined);
    useEffect(() => {
        const previous = previousTop.current;
        previousTop.current = topKey;
        if (topKey === previous) return;
        const stillOpen = previous !== undefined && active.some((item) => item.layer.key === previous);
        if (topKey && (previous === undefined || stillOpen)) {
            // Pushed: remember what had focus, focus the new panel.
            openers.current.set(topKey, document.activeElement);
            const element = elements.current.get(topKey);
            if (element && !element.contains(document.activeElement)) element.focus({ preventScroll: true });
        } else if (previous) {
            // Popped: restore focus inside the panel that is on top again.
            const opener = openers.current.get(previous);
            openers.current.delete(previous);
            const element = topKey ? elements.current.get(topKey) : null;
            if (opener instanceof HTMLElement && opener.isConnected && (!element || element.contains(opener))) opener.focus({ preventScroll: true });
            else element?.focus({ preventScroll: true });
        }
    }, [topKey, active]);

    // Escape pops the top layer; Tab stays inside it.
    useEffect(() => {
        if (active.length === 0) return;
        const onKeyDown = (event: KeyboardEvent) => {
            const top = topKey ? elements.current.get(topKey) : null;
            if (!top) return;
            if (event.key === 'Escape') {
                if (escapeHandledElsewhere(event)) return;
                const target = event.target as HTMLElement | null;
                if (target && top.contains(target) && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) {
                    target.blur();
                    top.focus({ preventScroll: true });

                    return;
                }
                event.preventDefault();
                onDismiss(active.length - 1);
            } else if (event.key === 'Tab' && top.contains(document.activeElement)) {
                const focusable = [...top.querySelectorAll<HTMLElement>(FOCUSABLE)].filter((element) => element.offsetParent !== null);
                if (focusable.length === 0) return;
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && (document.activeElement === first || document.activeElement === top)) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        };
        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [active.length, topKey, onDismiss]);

    const persistWidth = useCallback(() => {
        if (!resizeKey) return;
        setWidth((current) => {
            try {
                if (current) window.localStorage.setItem(`falak:panel-width:${resizeKey}`, String(Math.round(current)));
            } catch {
                // Storage unavailable: the width is just not remembered.
            }

            return current;
        });
    }, [resizeKey]);

    if (!mounted || rendered.length === 0) return null;

    let depthIndex = active.length;

    return createPortal(
        <div data-falak-panel-stack style={width ? ({ '--panel-width': `${width}px` } as CSSProperties) : undefined}>
            {rendered.map((item, index) => {
                const depth = item.leaving ? 0 : --depthIndex;
                const activeIndex = active.findIndex((entry) => entry.layer.key === item.layer.key);

                return (
                    <section
                        key={item.layer.key}
                        ref={(element) => {
                            if (element) elements.current.set(item.layer.key, element);
                            else elements.current.delete(item.layer.key);
                        }}
                        role="dialog"
                        aria-modal="false"
                        aria-label={item.layer.label}
                        aria-hidden={depth > 0 || item.leaving ? true : undefined}
                        inert={depth > 0 || item.leaving ? true : undefined}
                        data-falak-panel
                        data-depth={depth}
                        data-testid={`panel-layer-${index}`}
                        tabIndex={-1}
                        style={{ '--stack-depth': depth, zIndex: 40 + index } as CSSProperties}
                        className={cn(
                            'falak-panel border-border bg-surface-1 shadow-float fixed inset-x-0 top-12 bottom-0 flex flex-col overflow-hidden border-t outline-none',
                            'lg:top-[calc(3rem+12px)] lg:right-3 lg:bottom-3 lg:left-auto lg:w-(--panel-width,min(940px,58vw)) lg:rounded-xl lg:border',
                            item.leaving && 'pointer-events-none',
                            maximized && depth === 0 && !item.leaving && 'lg:left-3 lg:w-auto',
                        )}
                    >
                        {depth === 0 && !item.leaving && !maximized && <ResizeHandle onResize={setWidth} onDone={persistWidth} />}
                        {item.layer.content}
                        <div
                            className="falak-panel-veil"
                            aria-hidden
                            onClick={depth > 0 && activeIndex >= 0 ? () => onDismiss(activeIndex + 1) : undefined}
                        />
                    </section>
                );
            })}
        </div>,
        document.body,
    );
}

export interface PanelHeaderProps {
    icon?: ReactNode;
    /** Large title (service name); may be interactive (inline rename). */
    title: ReactNode;
    /** Breadcrumb before the title, e.g. the service in "Postgres / 4d2947b1". */
    parent?: ReactNode;
    status?: ReactNode;
    /** Right side, before the close button: primary action, `⋯` menu, timestamp. */
    actions?: ReactNode;
    subtitle?: ReactNode;
    onClose: () => void;
    /** Smaller header for stacked detail panels. */
    size?: 'lg' | 'md';
    className?: string;
}

/** Header of a stacked panel: icon tile, big name, status, actions and ✕ (Esc). */
export function PanelHeader({ icon, title, parent, status, actions, subtitle, onClose, size = 'lg', className }: PanelHeaderProps) {
    return (
        <header className={cn('flex items-start gap-3 px-5 pt-5 pb-3 sm:px-7', size === 'lg' ? 'sm:pt-7' : 'sm:pt-6', className)}>
            {icon && (
                <div
                    className={cn(
                        'border-border bg-surface-2 flex shrink-0 items-center justify-center rounded-lg border',
                        size === 'lg' ? 'size-10' : 'mt-0.5 size-8',
                    )}
                >
                    {icon}
                </div>
            )}
            <div className="grid min-w-0 flex-1 gap-0.5">
                <div className={cn('flex min-w-0 flex-wrap items-center gap-x-2.5 gap-y-1', size === 'lg' ? 'min-h-10' : 'min-h-9')}>
                    {parent && (
                        <>
                            <span className={cn('text-fg truncate font-semibold', size === 'lg' ? 'text-xl' : 'text-lg')}>{parent}</span>
                            <span className="text-fg-faint text-lg" aria-hidden>
                                /
                            </span>
                        </>
                    )}
                    <h2 className={cn('text-fg min-w-0 truncate font-semibold tracking-tight', size === 'lg' ? 'text-xl' : 'text-lg')}>{title}</h2>
                    {status}
                </div>
                {subtitle && <div className="text-fg-muted min-w-0 truncate text-xs">{subtitle}</div>}
            </div>
            <div className={cn('flex shrink-0 items-center gap-1.5', size === 'lg' ? 'mt-1' : 'mt-0.5')}>
                {actions}
                <IconButton label="Close" shortcut="Esc" icon={<X />} onClick={onClose} />
            </div>
        </header>
    );
}
