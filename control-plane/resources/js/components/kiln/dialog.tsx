import { cn } from '@/lib/utils';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { type ReactNode } from 'react';

export const DialogTrigger = DialogPrimitive.Trigger;
export const DialogClose = DialogPrimitive.Close;

export interface DialogProps {
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    trigger?: ReactNode;
    title: ReactNode;
    description?: ReactNode;
    /** Buttons row, right aligned. */
    footer?: ReactNode;
    size?: 'sm' | 'md' | 'lg';
    children?: ReactNode;
    className?: string;
}

const WIDTHS = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-2xl' } as const;

export function Dialog({ open, onOpenChange, trigger, title, description, footer, size = 'md', children, className }: DialogProps) {
    return (
        <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
            {trigger && <DialogPrimitive.Trigger asChild>{trigger}</DialogPrimitive.Trigger>}
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="animate-fade-in bg-overlay fixed inset-0 z-50" />
                <DialogPrimitive.Content
                    className={cn(
                        'animate-dialog-in border-border bg-surface-1 shadow-panel fixed top-[12vh] left-1/2 z-50 grid max-h-[80vh] w-[calc(100vw-2rem)] -translate-x-1/2 overflow-hidden rounded-xl border',
                        WIDTHS[size],
                        className,
                    )}
                >
                    <div className="flex items-start justify-between gap-4 px-5 pt-4">
                        <div className="grid gap-1">
                            <DialogPrimitive.Title className="text-fg text-base font-semibold">{title}</DialogPrimitive.Title>
                            {description ? (
                                <DialogPrimitive.Description className="text-fg-muted text-sm">{description}</DialogPrimitive.Description>
                            ) : (
                                <DialogPrimitive.Description className="sr-only">
                                    {typeof title === 'string' ? title : 'Dialog'}
                                </DialogPrimitive.Description>
                            )}
                        </div>
                        <DialogPrimitive.Close
                            className="text-fg-faint hover:bg-surface-2 hover:text-fg -mr-1 rounded-md p-1 transition-colors"
                            aria-label="Close"
                        >
                            <X className="size-4" />
                        </DialogPrimitive.Close>
                    </div>
                    <div className="overflow-y-auto px-5 py-4">{children}</div>
                    {footer && (
                        <div className="border-border bg-bg/40 flex flex-wrap items-center justify-end gap-2 border-t px-5 py-3">{footer}</div>
                    )}
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}
