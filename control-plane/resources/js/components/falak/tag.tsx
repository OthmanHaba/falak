import { cn } from '@/lib/utils';
import { type HTMLAttributes, type ReactNode } from 'react';
import { type StatusTone } from './status';

export interface TagProps extends HTMLAttributes<HTMLSpanElement> {
    tone?: 'neutral' | 'accent' | StatusTone;
    icon?: ReactNode;
    mono?: boolean;
}

const TONES: Record<NonNullable<TagProps['tone']>, string> = {
    neutral: 'border border-border bg-surface-2 text-fg-muted',
    accent: 'bg-primary-soft text-primary-on-soft',
    success: 'bg-success-soft text-success',
    warning: 'bg-warning-soft text-warning',
    info: 'bg-info-soft text-info',
    danger: 'bg-danger-soft text-danger',
    faint: 'bg-faint-soft text-fg-faint',
};

/** Small label (runtime, role, branch, server chip). Color only carries meaning for status tones. */
export function Tag({ tone = 'neutral', icon, mono, className, children, ...props }: TagProps) {
    return (
        <span
            className={cn(
                'inline-flex h-5 max-w-full shrink-0 items-center gap-1 rounded-sm px-1.5 text-xs font-medium whitespace-nowrap [&_svg]:size-3',
                TONES[tone],
                mono && 'text-2xs font-mono',
                className,
            )}
            {...props}
        >
            {icon}
            <span className="truncate">{children}</span>
        </span>
    );
}
