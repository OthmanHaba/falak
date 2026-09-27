import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';
import { Button } from './button';

export interface ChangesBarProps {
    /** Number of pending changes; the bar hides at 0. */
    count: number;
    /** "Deploy to apply", "Save to apply"… */
    message?: ReactNode;
    applyLabel?: string;
    onApply: () => void;
    onDiscard?: () => void;
    processing?: boolean;
    className?: string;
}

/** §1.5: sticky "3 changes — Deploy to apply" bar for edits that need a deploy. */
export function ChangesBar({
    count,
    message = 'Deploy to apply',
    applyLabel = 'Deploy',
    onApply,
    onDiscard,
    processing = false,
    className,
}: ChangesBarProps) {
    if (count <= 0) return null;

    return (
        <div
            role="region"
            aria-label="Pending changes"
            className={cn(
                'animate-dialog-in border-border-strong bg-surface-2 shadow-panel sticky bottom-4 z-20 mx-auto flex w-full max-w-xl items-center gap-3 rounded-xl border py-2 pr-2 pl-4',
                className,
            )}
        >
            <span className="bg-warning size-2 shrink-0 rounded-full" aria-hidden />
            <p className="text-fg min-w-0 flex-1 truncate text-sm" aria-live="polite">
                <span className="tabular font-medium">
                    {count} {count === 1 ? 'change' : 'changes'}
                </span>
                <span className="text-fg-muted"> — {message}</span>
            </p>
            {onDiscard && (
                <Button variant="ghost" size="sm" onClick={onDiscard} disabled={processing}>
                    Discard
                </Button>
            )}
            <Button variant="primary" size="sm" onClick={onApply} loading={processing}>
                {applyLabel}
            </Button>
        </div>
    );
}
