import { cn } from '@/lib/utils';

export type StatusTone = 'success' | 'warning' | 'info' | 'danger' | 'faint';

interface StatusSpec {
    tone: StatusTone;
    pulse: boolean;
    label: string;
}

/**
 * The one status language (docs/UI_DESIGN.md §2):
 * success = active/online/healthy/succeeded · warning+pulse = building/deploying/provisioning/running ·
 * info = queued/waiting · danger = failed/crashed/offline/error · faint = removed/inactive/skipped/cancelled.
 */
const STATUSES: Record<string, StatusSpec> = {
    active: { tone: 'success', pulse: false, label: 'Active' },
    online: { tone: 'success', pulse: false, label: 'Online' },
    healthy: { tone: 'success', pulse: false, label: 'Healthy' },
    succeeded: { tone: 'success', pulse: false, label: 'Success' },
    success: { tone: 'success', pulse: false, label: 'Success' },

    building: { tone: 'warning', pulse: true, label: 'Building' },
    deploying: { tone: 'warning', pulse: true, label: 'Deploying' },
    provisioning: { tone: 'warning', pulse: true, label: 'Provisioning' },
    running: { tone: 'warning', pulse: true, label: 'Running' },
    'queued-running': { tone: 'warning', pulse: true, label: 'Running' },
    degraded: { tone: 'warning', pulse: false, label: 'Degraded' },

    queued: { tone: 'info', pulse: false, label: 'Queued' },
    waiting: { tone: 'info', pulse: false, label: 'Queued' },
    pending: { tone: 'info', pulse: false, label: 'Queued' },

    failed: { tone: 'danger', pulse: false, label: 'Failed' },
    crashed: { tone: 'danger', pulse: false, label: 'Crashed' },
    offline: { tone: 'danger', pulse: false, label: 'Offline' },
    error: { tone: 'danger', pulse: false, label: 'Failed' },

    removed: { tone: 'faint', pulse: false, label: 'Removed' },
    inactive: { tone: 'faint', pulse: false, label: 'Inactive' },
    skipped: { tone: 'faint', pulse: false, label: 'Skipped' },
    cancelled: { tone: 'faint', pulse: false, label: 'Cancelled' },
    canceled: { tone: 'faint', pulse: false, label: 'Cancelled' },
};

export function statusSpec(status: string): StatusSpec {
    const key = status.toLowerCase().replace(/[\s_]+/g, '-');

    return STATUSES[key] ?? { tone: 'faint', pulse: false, label: status.charAt(0).toUpperCase() + status.slice(1).replace(/[_-]+/g, ' ') };
}

const DOT: Record<StatusTone, string> = {
    success: 'bg-success text-success',
    warning: 'bg-warning text-warning',
    info: 'bg-info text-info',
    danger: 'bg-danger text-danger',
    faint: 'bg-fg-faint text-fg-faint',
};

const BADGE: Record<StatusTone, string> = {
    success: 'bg-success-soft text-success',
    warning: 'bg-warning-soft text-warning',
    info: 'bg-info-soft text-info',
    danger: 'bg-danger-soft text-danger',
    faint: 'bg-faint-soft text-fg-muted',
};

export interface StatusDotProps {
    status: string;
    /** Override the tone derived from `status`. */
    tone?: StatusTone;
    pulse?: boolean;
    size?: 'sm' | 'md';
    /** When set, the dot is announced to screen readers (otherwise decorative). */
    label?: string;
    className?: string;
}

export function StatusDot({ status, tone, pulse, size = 'md', label, className }: StatusDotProps) {
    const spec = statusSpec(status);
    const resolvedTone = tone ?? spec.tone;
    const pulsing = pulse ?? spec.pulse;

    return (
        <span
            role={label ? 'img' : undefined}
            aria-label={label}
            aria-hidden={label ? undefined : true}
            data-status={status}
            data-tone={resolvedTone}
            className={cn(
                'inline-block shrink-0 rounded-full',
                size === 'sm' ? 'size-1.5' : 'size-2',
                DOT[resolvedTone],
                pulsing && 'animate-pulse-dot',
                className,
            )}
        />
    );
}

export interface StatusBadgeProps {
    status: string;
    /** Override the label derived from `status` (e.g. "Deploying 64%"). */
    label?: string;
    tone?: StatusTone;
    pulse?: boolean;
    className?: string;
}

export function StatusBadge({ status, label, tone, pulse, className }: StatusBadgeProps) {
    const spec = statusSpec(status);
    const resolvedTone = tone ?? spec.tone;

    return (
        <span
            className={cn(
                'inline-flex h-5 shrink-0 items-center gap-1.5 rounded-full px-2 text-xs font-medium whitespace-nowrap',
                BADGE[resolvedTone],
                className,
            )}
        >
            <StatusDot status={status} tone={resolvedTone} pulse={pulse} size="sm" />
            {label ?? spec.label}
        </span>
    );
}

const PILL: Record<StatusTone, string> = {
    success: 'border-success/30 bg-success-soft text-success',
    warning: 'border-warning/30 bg-warning-soft text-warning',
    info: 'border-info/30 bg-info-soft text-info',
    danger: 'border-danger/30 bg-danger-soft text-danger',
    faint: 'border-border bg-surface-2 text-fg-muted',
};

/** Uppercase state pill of a deployment card ("ACTIVE", "FAILED", "SUPERSEDED"). */
export function StatusPill({ status, label, tone, className }: { status: string; label?: string; tone?: StatusTone; className?: string }) {
    const spec = statusSpec(status);

    return (
        <span
            className={cn(
                'text-2xs inline-flex h-5 shrink-0 items-center rounded-md border px-1.5 font-semibold tracking-[0.06em] whitespace-nowrap uppercase',
                PILL[tone ?? spec.tone],
                className,
            )}
        >
            {label ?? spec.label}
        </span>
    );
}
