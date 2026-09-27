import { cn } from '@/lib/utils';
import { CircleAlert, CircleCheck, Info, TriangleAlert } from 'lucide-react';
import { type ReactNode } from 'react';

export interface CalloutProps {
    tone?: 'info' | 'success' | 'warning' | 'danger';
    title?: ReactNode;
    children?: ReactNode;
    /** Right side (e.g. a Retry or Dismiss button). */
    action?: ReactNode;
    className?: string;
}

const TONES = {
    info: { box: 'border-info/30 bg-info-soft', icon: 'text-info', Icon: Info },
    success: { box: 'border-success/30 bg-success-soft', icon: 'text-success', Icon: CircleCheck },
    warning: { box: 'border-warning/30 bg-warning-soft', icon: 'text-warning', Icon: TriangleAlert },
    danger: { box: 'border-danger/30 bg-danger-soft', icon: 'text-danger', Icon: CircleAlert },
} as const;

/** Inline banner for results and notices (verification outcome, one-time token, OAuth error). */
export function Callout({ tone = 'info', title, children, action, className }: CalloutProps) {
    const spec = TONES[tone];

    return (
        <div
            role={tone === 'danger' ? 'alert' : 'status'}
            className={cn('flex items-start gap-2.5 rounded-lg border px-3 py-2.5', spec.box, className)}
        >
            <spec.Icon className={cn('mt-0.5 size-4 shrink-0', spec.icon)} aria-hidden />
            <div className="grid min-w-0 flex-1 gap-1 text-sm">
                {title && <p className="text-fg font-medium">{title}</p>}
                {children && <div className="text-fg-muted min-w-0 break-words">{children}</div>}
            </div>
            {action && <div className="flex shrink-0 items-center gap-2">{action}</div>}
        </div>
    );
}
