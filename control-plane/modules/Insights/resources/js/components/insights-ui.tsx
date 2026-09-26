import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { formatDistanceToNowStrict } from 'date-fns';
import { AlertTriangle, CheckCircle2, CircleDot, EyeOff, Gauge, HeartPulse } from 'lucide-react';
import { useState } from 'react';
import { type Frame, type IssueKind, type IssuePriority, type IssueStatus } from '../types';

/** Categorical slots (validated reference palette, light/dark steps are close enough for these marks). */
export const SERIES = { primary: '#2a78d6', secondary: '#eb6834', tertiary: '#1baf7a' } as const;

export function formatMs(ms: number | null | undefined): string {
    if (ms === null || ms === undefined) return '—';
    if (ms >= 1000) return `${(ms / 1000).toFixed(ms >= 10_000 ? 0 : 2)} s`;

    return `${ms.toFixed(ms >= 100 ? 0 : 1)} ms`;
}

export function formatCount(value: number): string {
    return new Intl.NumberFormat(undefined, { notation: value >= 10_000 ? 'compact' : 'standard' }).format(value);
}

export function ago(iso: string | null): string {
    return iso ? `${formatDistanceToNowStrict(new Date(iso))} ago` : 'never';
}

const STATUS: Record<IssueStatus, { label: string; icon: typeof CircleDot; className: string }> = {
    open: { label: 'Open', icon: CircleDot, className: 'border-red-500/40 text-red-700 dark:text-red-300' },
    resolved: { label: 'Resolved', icon: CheckCircle2, className: 'border-emerald-500/40 text-emerald-700 dark:text-emerald-300' },
    ignored: { label: 'Ignored', icon: EyeOff, className: 'text-muted-foreground' },
};

export function IssueStatusBadge({ status }: { status: IssueStatus }) {
    const { label, icon: Icon, className } = STATUS[status];

    return (
        <Badge variant="outline" className={cn('gap-1', className)}>
            <Icon className="size-3" aria-hidden /> {label}
        </Badge>
    );
}

const KIND: Record<IssueKind, { label: string; icon: typeof CircleDot }> = {
    exception: { label: 'Exception', icon: AlertTriangle },
    performance: { label: 'Performance', icon: Gauge },
    heartbeat: { label: 'Scheduled task', icon: HeartPulse },
};

export function IssueKindBadge({ kind }: { kind: IssueKind }) {
    const { label, icon: Icon } = KIND[kind];

    return (
        <Badge variant="secondary" className="gap-1">
            <Icon className="size-3" aria-hidden /> {label}
        </Badge>
    );
}

const PRIORITY_CLASS: Record<IssuePriority, string> = {
    none: 'text-muted-foreground',
    low: 'text-muted-foreground',
    medium: 'border-amber-500/40 text-amber-700 dark:text-amber-300',
    high: 'border-orange-500/50 text-orange-700 dark:text-orange-300',
    urgent: 'border-red-500/60 text-red-700 dark:text-red-300',
};

export function PriorityBadge({ priority }: { priority: IssuePriority }) {
    if (priority === 'none') return null;

    return (
        <Badge variant="outline" className={PRIORITY_CLASS[priority]}>
            {priority}
        </Badge>
    );
}

export function StatTile({ label, value, hint, tone }: { label: string; value: string; hint?: string; tone?: 'danger' }) {
    return (
        <Card className="py-4">
            <CardContent className="space-y-1 px-4">
                <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">{label}</p>
                <p className={cn('text-2xl font-semibold tabular-nums', tone === 'danger' && 'text-red-600 dark:text-red-400')}>{value}</p>
                {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
            </CardContent>
        </Card>
    );
}

/** Parsed stack trace: in-app frames highlighted, vendor frames collapsible. */
export function StackTraceView({ frames, raw }: { frames: Frame[]; raw: string | null }) {
    const [showAll, setShowAll] = useState(false);
    const [showRaw, setShowRaw] = useState(false);

    if (frames.length === 0 && !raw) {
        return <p className="text-muted-foreground text-sm">No stack trace was reported.</p>;
    }

    const visible = showAll ? frames : frames.filter((frame) => frame.in_app);
    const hidden = frames.length - visible.length;

    return (
        <div className="space-y-2">
            <div className="flex gap-3 text-xs">
                {hidden > 0 || showAll ? (
                    <button type="button" className="text-muted-foreground underline-offset-2 hover:underline" onClick={() => setShowAll(!showAll)}>
                        {showAll ? 'Only application frames' : `Show ${hidden} vendor frame${hidden === 1 ? '' : 's'}`}
                    </button>
                ) : null}
                {raw && (
                    <button type="button" className="text-muted-foreground underline-offset-2 hover:underline" onClick={() => setShowRaw(!showRaw)}>
                        {showRaw ? 'Parsed' : 'Raw'}
                    </button>
                )}
            </div>
            {showRaw && raw ? (
                <pre className="bg-muted max-h-[32rem] overflow-auto rounded-md p-3 font-mono text-xs leading-relaxed whitespace-pre">{raw}</pre>
            ) : (
                <ol className="divide-y rounded-md border font-mono text-xs">
                    {(visible.length > 0 ? visible : frames).map((frame, index) => (
                        <li key={index} className={cn('px-3 py-2', frame.in_app ? 'bg-background' : 'bg-muted/40 text-muted-foreground')}>
                            {frame.file ? (
                                <>
                                    <span className="font-semibold">{frame.function ?? '(anonymous)'}</span>
                                    <span className="text-muted-foreground">
                                        {' '}
                                        {frame.file}
                                        {frame.line ? `:${frame.line}` : ''}
                                    </span>
                                </>
                            ) : (
                                <span>{frame.raw}</span>
                            )}
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}
