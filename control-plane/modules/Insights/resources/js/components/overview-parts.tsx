import { EmptyState } from '@/components/kiln/empty-state';
import { RelativeTime } from '@/components/kiln/relative-time';
import { Tag } from '@/components/kiln/tag';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { format } from 'date-fns';
import { CircleCheck } from 'lucide-react';
import { type ReactNode } from 'react';
import { type TopEntry, type TopIssue } from '../types';
import { formatCount, formatMs, KIND, PriorityTag } from './insights-ui';

/** x-axis labels: time of day for short windows, day + time for multi-day ones. */
export function timeFormatFor(range: string): (value: number | string) => string {
    const multiDay = range === '7d' || range === '30d';

    return (value) => {
        const date = typeof value === 'number' ? new Date(value < 1e12 ? value * 1000 : value) : new Date(value);
        if (Number.isNaN(date.getTime())) return String(value);

        return format(date, multiDay ? 'MMM d' : 'HH:mm');
    };
}

/** A titled list block without heavy card chrome. */
export function Block({ title, aside, children, className }: { title: ReactNode; aside?: ReactNode; children: ReactNode; className?: string }) {
    return (
        <section className={cn('border-border bg-surface-1 grid min-w-0 content-start overflow-hidden rounded-lg border', className)}>
            <header className="border-border flex min-h-10 items-center justify-between gap-2 border-b px-4 py-2">
                <h2 className="text-fg text-sm font-medium">{title}</h2>
                {aside && <div className="flex items-center gap-2 text-xs">{aside}</div>}
            </header>
            {children}
        </section>
    );
}

/** Slow routes / queries / jobs: name + the emphasized measure with a relative bar. */
export function TopList({ rows, emphasis, empty }: { rows: TopEntry[]; emphasis: 'p95' | 'max' | 'count'; empty: string }) {
    if (rows.length === 0) {
        return <p className="text-fg-faint px-4 py-6 text-center text-sm">{empty}</p>;
    }

    const value = (row: TopEntry) => (emphasis === 'p95' ? row.p95_ms : emphasis === 'max' ? row.max_ms : row.count);
    const max = Math.max(...rows.map(value), 1);

    return (
        <ul className="divide-border divide-y">
            {rows.map((row) => (
                <li key={row.name} className="grid gap-1 px-4 py-2">
                    <div className="flex min-w-0 items-baseline justify-between gap-3">
                        <span className="text-fg truncate font-mono text-xs" title={row.name}>
                            {row.name}
                        </span>
                        <span className="text-fg tabular shrink-0 text-xs font-medium">
                            {emphasis === 'count' ? formatCount(row.count) : formatMs(value(row))}
                        </span>
                    </div>
                    <div className="flex items-center gap-3">
                        <span className="bg-surface-2 relative h-1 flex-1 overflow-hidden rounded-full" aria-hidden>
                            <span
                                className="bg-chart-1 absolute inset-y-0 left-0 rounded-full"
                                style={{ width: `${Math.max(2, (value(row) / max) * 100)}%` }}
                            />
                        </span>
                        <span className="text-fg-faint tabular text-2xs shrink-0">
                            {formatCount(row.count)} calls
                            {emphasis !== 'p95' && ` · p95 ${formatMs(row.p95_ms)}`}
                            {emphasis === 'p95' && ` · max ${formatMs(row.max_ms)}`}
                            {row.errors > 0 && <span className="text-danger"> · {formatCount(row.errors)} failed</span>}
                        </span>
                    </div>
                </li>
            ))}
        </ul>
    );
}

/** Top open issues with their in-window count. */
export function IssueList({
    issues,
    siteNames = {},
    emptyAction,
}: {
    issues: TopIssue[];
    siteNames?: Record<string, string>;
    emptyAction?: ReactNode;
}) {
    if (issues.length === 0) {
        return (
            <EmptyState
                size="sm"
                className="m-4"
                icon={<CircleCheck />}
                title="No open issues"
                description="Exceptions, slow endpoints and missed scheduled tasks open issues here."
                action={emptyAction}
            />
        );
    }

    return (
        <ul className="divide-border divide-y">
            {issues.map((issue) => {
                const Icon = KIND[issue.kind].icon;

                return (
                    <li key={issue.id}>
                        <Link
                            href={`/observability/issues/${issue.id}`}
                            className="hover:bg-surface-2 flex min-w-0 items-start gap-3 px-4 py-2.5 transition-colors duration-150"
                        >
                            <Icon className={cn('mt-0.5 size-4 shrink-0', issue.handled === false ? 'text-danger' : 'text-fg-faint')} aria-hidden />
                            <span className="grid min-w-0 flex-1 gap-0.5">
                                <span className="text-fg truncate text-sm">{issue.title}</span>
                                <span className="text-fg-faint flex min-w-0 flex-wrap items-center gap-x-2 text-xs">
                                    {issue.culprit && <span className="truncate font-mono">{issue.culprit}</span>}
                                    {issue.site_id && siteNames[issue.site_id] && <span>{siteNames[issue.site_id]}</span>}
                                    <RelativeTime value={issue.last_seen_at} />
                                </span>
                            </span>
                            <span className="flex shrink-0 flex-col items-end gap-1">
                                <span className="text-fg tabular text-sm font-medium" title={`${issue.occurrences} occurrences in total`}>
                                    {formatCount(issue.occurrences_in_range || issue.occurrences)}
                                </span>
                                <span className="flex items-center gap-1">
                                    {issue.handled === false && <Tag tone="danger">Unhandled</Tag>}
                                    <PriorityTag priority={issue.priority} />
                                </span>
                            </span>
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}
