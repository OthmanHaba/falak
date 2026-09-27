import { cn } from '@/lib/utils';
import { router, usePage } from '@inertiajs/react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { useCallback, useEffect, useMemo, type ReactNode } from 'react';
import { IconButton } from './button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from './tabs';

export interface PanelTab {
    id: string;
    label: string;
    badge?: ReactNode;
    /** Rendered only while the tab is active (tabs load their data lazily). */
    content: ReactNode | (() => ReactNode);
}

/**
 * How the active tab is reflected in the URL (§1.2 deep links):
 * - `query`: `?tab=variables` (param configurable)
 * - `segment`: `${base}/${tab}` e.g. `/projects/p/production/service/site/01H…/variables`
 */
export type PanelUrlSync = { mode: 'query'; param?: string } | { mode: 'segment'; base: string };

export interface PanelProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: ReactNode;
    /** Accessible description; visually hidden unless `subtitle` is used. */
    description?: string;
    subtitle?: ReactNode;
    icon?: ReactNode;
    status?: ReactNode;
    /** Primary action + `⋯` menu, rendered in the header. */
    actions?: ReactNode;
    tabs?: PanelTab[];
    /** Controlled active tab; otherwise derived from the URL (urlSync) or the first tab. */
    tab?: string;
    onTabChange?: (tab: string) => void;
    urlSync?: PanelUrlSync;
    /** Content when there are no tabs, or above the tab body. */
    children?: ReactNode;
    className?: string;
}

function tabFromUrl(url: string, tabs: PanelTab[], sync: PanelUrlSync | undefined): string | undefined {
    if (!sync) return undefined;
    const [path, query = ''] = url.split('?');

    if (sync.mode === 'query') {
        return new URLSearchParams(query).get(sync.param ?? 'tab') ?? undefined;
    }

    const base = sync.base.replace(/\/$/, '');
    if (!path.startsWith(`${base}/`)) return undefined;
    const segment = path.slice(base.length + 1).split('/')[0];

    return tabs.some((tab) => tab.id === segment) ? segment : undefined;
}

function urlForTab(url: string, tab: string, sync: PanelUrlSync): string {
    const [path, query = ''] = url.split('?');

    if (sync.mode === 'query') {
        const params = new URLSearchParams(query);
        params.set(sync.param ?? 'tab', tab);

        return `${path}?${params.toString()}`;
    }

    return `${sync.base.replace(/\/$/, '')}/${tab}${query ? `?${query}` : ''}`;
}

/**
 * Right-anchored slide-over (service panel, server panel). Width min(960px, 62vw), full screen below 1024px.
 * Esc / click outside close it; `[` and `]` move between tabs; the active tab is kept in the URL.
 */
export function Panel({
    open,
    onOpenChange,
    title,
    description,
    subtitle,
    icon,
    status,
    actions,
    tabs = [],
    tab,
    onTabChange,
    urlSync,
    children,
    className,
}: PanelProps) {
    const { url } = usePage();
    const active = useMemo(() => tab ?? tabFromUrl(url, tabs, urlSync) ?? tabs[0]?.id, [tab, url, tabs, urlSync]);

    const select = useCallback(
        (next: string) => {
            onTabChange?.(next);
            if (urlSync && next !== active) {
                router.push({ url: urlForTab(url, next, urlSync), preserveScroll: true, preserveState: true });
            }
        },
        [onTabChange, urlSync, active, url],
    );

    useEffect(() => {
        if (!open || tabs.length < 2) return;

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key !== '[' && event.key !== ']') return;
            const target = event.target as HTMLElement | null;
            if (target && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) return;

            const index = tabs.findIndex((item) => item.id === active);
            const nextIndex = (index + (event.key === ']' ? 1 : -1) + tabs.length) % tabs.length;
            event.preventDefault();
            select(tabs[nextIndex].id);
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [open, tabs, active, select]);

    return (
        <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="animate-fade-in bg-overlay/40 fixed inset-0 z-40" />
                <DialogPrimitive.Content
                    className={cn(
                        'animate-panel-in border-border bg-surface-1 shadow-panel fixed inset-y-0 right-0 z-40 flex w-full flex-col border-l outline-none lg:top-2 lg:right-2 lg:bottom-2 lg:w-[min(960px,62vw)] lg:rounded-xl lg:border',
                        className,
                    )}
                >
                    <header className="flex items-start gap-3 px-5 pt-4 pb-3">
                        {icon && (
                            <div className="border-border bg-surface-2 mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg border">
                                {icon}
                            </div>
                        )}
                        <div className="grid min-w-0 flex-1 gap-0.5">
                            <div className="flex min-w-0 flex-wrap items-center gap-2">
                                <DialogPrimitive.Title className="text-fg truncate text-base font-semibold">{title}</DialogPrimitive.Title>
                                {status}
                            </div>
                            {subtitle && <div className="text-fg-muted truncate text-xs">{subtitle}</div>}
                            <DialogPrimitive.Description className="sr-only">
                                {description ?? (typeof title === 'string' ? title : 'Panel')}
                            </DialogPrimitive.Description>
                        </div>
                        <div className="flex shrink-0 items-center gap-1.5">
                            {actions}
                            <DialogPrimitive.Close asChild>
                                <IconButton label="Close" shortcut="Esc" icon={<X />} />
                            </DialogPrimitive.Close>
                        </div>
                    </header>
                    {tabs.length > 0 ? (
                        <Tabs value={active} onValueChange={select} className="flex min-h-0 flex-1 flex-col">
                            <TabsList className="px-4">
                                {tabs.map((item) => (
                                    <TabsTrigger key={item.id} value={item.id} badge={item.badge}>
                                        {item.label}
                                    </TabsTrigger>
                                ))}
                            </TabsList>
                            {children}
                            {tabs.map((item) => (
                                <TabsContent key={item.id} value={item.id} className="min-h-0 flex-1 overflow-y-auto p-5">
                                    {item.id === active && (typeof item.content === 'function' ? item.content() : item.content)}
                                </TabsContent>
                            ))}
                        </Tabs>
                    ) : (
                        <div className="border-border min-h-0 flex-1 overflow-y-auto border-t p-5">{children}</div>
                    )}
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}
