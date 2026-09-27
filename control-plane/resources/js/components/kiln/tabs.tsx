import { cn } from '@/lib/utils';
import * as TabsPrimitive from '@radix-ui/react-tabs';
import { forwardRef, type ComponentPropsWithoutRef, type ReactNode } from 'react';

export const Tabs = TabsPrimitive.Root;
export const TabsContent = forwardRef<HTMLDivElement, ComponentPropsWithoutRef<typeof TabsPrimitive.Content>>(({ className, ...props }, ref) => (
    <TabsPrimitive.Content ref={ref} className={cn('outline-none', className)} {...props} />
));
TabsContent.displayName = 'TabsContent';

export const TabsList = forwardRef<HTMLDivElement, ComponentPropsWithoutRef<typeof TabsPrimitive.List>>(({ className, ...props }, ref) => (
    <TabsPrimitive.List
        ref={ref}
        className={cn('border-border flex [scrollbar-width:none] items-center gap-1 overflow-x-auto border-b', className)}
        {...props}
    />
));
TabsList.displayName = 'TabsList';

export const tabTriggerClasses =
    '-mb-px inline-flex h-9 shrink-0 items-center gap-1.5 border-b-2 border-transparent px-2.5 text-sm font-medium whitespace-nowrap text-fg-muted transition-colors duration-150 hover:text-fg data-[state=active]:border-primary data-[state=active]:text-fg aria-[current=page]:border-primary aria-[current=page]:text-fg';

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
