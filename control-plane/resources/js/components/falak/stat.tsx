import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';
import { type StatusTone } from './status';

export interface StatProps {
    label: ReactNode;
    value: ReactNode;
    /** Secondary line under the value (e.g. "12 unhandled"). */
    hint?: ReactNode;
    /** Colors the value only when it carries status meaning (errors > 0 → danger). */
    tone?: StatusTone;
    /** Small trend under the hint (e.g. a <Sparkline>). */
    trend?: ReactNode;
    href?: string;
    className?: string;
}

const VALUE_TONES: Record<StatusTone, string> = {
    success: 'text-success',
    warning: 'text-warning',
    info: 'text-info',
    danger: 'text-danger',
    faint: 'text-fg-muted',
};

/** Headline number tile (requests, error rate, p95 …). Use a row of these above charts. */
export function Stat({ label, value, hint, tone, trend, href, className }: StatProps) {
    const body = (
        <>
            <span className="text-fg-muted truncate text-xs font-medium">{label}</span>
            <span className={cn('tabular text-xl leading-7 font-semibold', tone ? VALUE_TONES[tone] : 'text-fg')}>{value}</span>
            {hint && <span className="text-fg-faint truncate text-xs">{hint}</span>}
            {trend && <span className="mt-1">{trend}</span>}
        </>
    );
    const classes = cn('border-border bg-surface-1 grid min-w-0 content-start gap-0.5 rounded-lg border px-4 py-3', className);

    return href ? (
        <Link href={href} className={cn(classes, 'hover:border-border-strong transition-colors duration-150')}>
            {body}
        </Link>
    ) : (
        <div className={classes}>{body}</div>
    );
}
