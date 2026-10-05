import { cn } from '@/lib/utils';

/** Falak mark: a faceted diamond in the accent color. */
export function FalakMark({ className, size = 20 }: { className?: string; size?: number }) {
    return (
        <svg viewBox="0 0 24 24" width={size} height={size} className={cn('text-primary shrink-0', className)} aria-hidden>
            <path d="M12 1.5 22.5 12 12 22.5 1.5 12Z" fill="currentColor" opacity="0.28" />
            <path d="M12 5.5 18.5 12 12 18.5 5.5 12Z" fill="currentColor" />
        </svg>
    );
}

export function FalakLogo({ className }: { className?: string }) {
    return (
        <span className={cn('text-fg inline-flex items-center gap-2 text-sm font-semibold tracking-tight', className)}>
            <FalakMark />
            Falak
        </span>
    );
}
