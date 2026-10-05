import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

export interface EmptyStateProps {
    icon?: ReactNode;
    title: ReactNode;
    /** Explain what would be here and why it's useful (§1.8 "empty states teach"). */
    description?: ReactNode;
    /** The one action that fills this list. */
    action?: ReactNode;
    secondary?: ReactNode;
    size?: 'sm' | 'md';
    className?: string;
}

export function EmptyState({ icon, title, description, action, secondary, size = 'md', className }: EmptyStateProps) {
    return (
        <div
            className={cn(
                'border-border flex flex-col items-center justify-center rounded-lg border border-dashed text-center',
                size === 'md' ? 'gap-3 px-6 py-14' : 'gap-2 px-4 py-8',
                className,
            )}
        >
            {icon && (
                <div className="border-border bg-surface-2 text-fg-muted flex size-10 items-center justify-center rounded-lg border [&_svg]:size-5">
                    {icon}
                </div>
            )}
            <div className="grid max-w-md gap-1">
                <h3 className={cn('text-fg font-medium', size === 'md' ? 'text-base' : 'text-sm')}>{title}</h3>
                {description && <p className="text-fg-muted text-sm">{description}</p>}
            </div>
            {(action || secondary) && (
                <div className="mt-1 flex flex-wrap items-center justify-center gap-2">
                    {action}
                    {secondary}
                </div>
            )}
        </div>
    );
}
