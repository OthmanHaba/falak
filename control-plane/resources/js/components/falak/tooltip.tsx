import { cn } from '@/lib/utils';
import * as TooltipPrimitive from '@radix-ui/react-tooltip';
import { type ReactElement, type ReactNode } from 'react';
import { Kbd } from './kbd';

export const TooltipProvider = TooltipPrimitive.Provider;

interface TooltipProps {
    content: ReactNode;
    children: ReactElement;
    side?: 'top' | 'right' | 'bottom' | 'left';
    shortcut?: string;
    className?: string;
}

/** Hover/focus hint. Wrap in <TooltipProvider> (AppShell does) — standalone use creates its own provider. */
export function Tooltip({ content, children, side = 'bottom', shortcut, className }: TooltipProps) {
    return (
        <TooltipPrimitive.Provider delayDuration={300}>
            <TooltipPrimitive.Root>
                <TooltipPrimitive.Trigger asChild>{children}</TooltipPrimitive.Trigger>
                <TooltipPrimitive.Portal>
                    <TooltipPrimitive.Content
                        side={side}
                        sideOffset={6}
                        className={cn(
                            'animate-fade-in border-border bg-surface-3 text-fg z-50 flex items-center gap-2 rounded-md border px-2 py-1 text-xs',
                            className,
                        )}
                    >
                        {content}
                        {shortcut && <Kbd>{shortcut}</Kbd>}
                    </TooltipPrimitive.Content>
                </TooltipPrimitive.Portal>
            </TooltipPrimitive.Root>
        </TooltipPrimitive.Provider>
    );
}
