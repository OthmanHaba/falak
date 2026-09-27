import { cn } from '@/lib/utils';
import { type HTMLAttributes } from 'react';

/** Keyboard key hint ("⌘K", "G S"). */
export function Kbd({ className, ...props }: HTMLAttributes<HTMLElement>) {
    return (
        <kbd
            className={cn(
                'border-border bg-surface-2 text-2xs text-fg-muted inline-flex h-5 min-w-5 items-center justify-center gap-0.5 rounded-sm border px-1 font-mono',
                className,
            )}
            {...props}
        />
    );
}
