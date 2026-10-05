import { cn } from '@/lib/utils';
import * as TabsPrimitive from '@radix-ui/react-tabs';
import { forwardRef, useCallback, useImperativeHandle, useLayoutEffect, useRef, type ComponentPropsWithoutRef, type ReactNode } from 'react';

export const Tabs = TabsPrimitive.Root;

/** Tab content fades in (and rises 3px) when it becomes active; instant with reduced motion. */
export const TabsContent = forwardRef<HTMLDivElement, ComponentPropsWithoutRef<typeof TabsPrimitive.Content>>(({ className, ...props }, ref) => (
    <TabsPrimitive.Content ref={ref} className={cn('data-[state=active]:animate-tab-in outline-none', className)} {...props} />
));
TabsContent.displayName = 'TabsContent';

/**
 * Keeps one accent bar under the active tab of a list and slides it (transform only) when the active tab changes.
 * Works for Radix triggers (`data-state=active`) and link tabs (`aria-current=page`).
 */
export function useTabIndicator<T extends HTMLElement>() {
    const list = useRef<T>(null);
    const indicator = useRef<HTMLSpanElement>(null);

    const place = useCallback((animate: boolean) => {
        const root = list.current;
        const bar = indicator.current;
        if (!root || !bar) return;
        const active = root.querySelector<HTMLElement>('[data-state="active"][role="tab"], [aria-current="page"]');
        if (!active) {
            bar.style.opacity = '0';

            return;
        }
        if (!animate) bar.style.transition = 'none';
        bar.style.opacity = '1';
        bar.style.transform = `translateX(${active.offsetLeft}px) scaleX(${active.offsetWidth / 100})`;
        if (!animate) {
            // Re-enable the transition after this frame so the first placement doesn't slide in from the left edge.
            void bar.offsetWidth;
            bar.style.transition = '';
        }
    }, []);

    useLayoutEffect(() => {
        const root = list.current;
        if (!root) return;
        place(false);
        const mutations = new MutationObserver(() => place(true));
        mutations.observe(root, { attributes: true, subtree: true, attributeFilter: ['data-state', 'aria-current'] });
        const sizes = new ResizeObserver(() => place(false));
        sizes.observe(root);
        // Fonts change tab widths once they load.
        void document.fonts?.ready.then(() => place(false));

        return () => {
            mutations.disconnect();
            sizes.disconnect();
        };
    }, [place]);

    return { list, indicator: <span ref={indicator} className="falak-tab-indicator" style={{ opacity: 0 }} aria-hidden /> };
}

export const TabsList = forwardRef<HTMLDivElement, ComponentPropsWithoutRef<typeof TabsPrimitive.List>>(({ className, children, ...props }, ref) => {
    const { list, indicator } = useTabIndicator<HTMLDivElement>();
    useImperativeHandle(ref, () => list.current as HTMLDivElement);

    return (
        <TabsPrimitive.List
            ref={list}
            className={cn('border-border relative flex [scrollbar-width:none] items-center gap-1 overflow-x-auto border-b', className)}
            {...props}
        >
            {children}
            {indicator}
        </TabsPrimitive.List>
    );
});
TabsList.displayName = 'TabsList';

/** Trigger styles: Radix tabs get the sliding indicator of their list; link tabs (aria-current) keep a border underline. */
export const tabTriggerClasses =
    '-mb-px inline-flex h-9 shrink-0 items-center gap-1.5 border-b-2 border-transparent px-2.5 text-sm font-medium whitespace-nowrap text-fg-muted transition-colors duration-150 hover:text-fg data-[state=active]:text-fg aria-[current=page]:border-primary aria-[current=page]:text-fg';

export const TabsTrigger = forwardRef<HTMLButtonElement, ComponentPropsWithoutRef<typeof TabsPrimitive.Trigger> & { badge?: ReactNode }>(
    ({ className, badge, children, ...props }, ref) => (
        <TabsPrimitive.Trigger ref={ref} className={cn(tabTriggerClasses, className)} {...props}>
            {children}
            {badge !== undefined && badge !== null && (
                <span className="bg-surface-3 text-2xs text-fg-muted tabular rounded-full px-1.5">{badge}</span>
            )}
        </TabsPrimitive.Trigger>
    ),
);
TabsTrigger.displayName = 'TabsTrigger';
