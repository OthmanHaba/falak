import { cn } from '@/lib/utils';
import { type HTMLAttributes } from 'react';

export function Skeleton({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div aria-hidden className={cn('bg-surface-2 animate-pulse rounded-md', className)} {...props} />;
}

/** N placeholder rows for lists/tables. */
export function SkeletonRows({ rows = 5, className }: { rows?: number; className?: string }) {
    return (
        <div className={cn('grid gap-2', className)} role="status" aria-label="Loading">
            {Array.from({ length: rows }, (_, index) => (
                <Skeleton key={index} className="h-9" style={{ opacity: 1 - index * (0.6 / rows) }} />
            ))}
        </div>
    );
}
