import { Button, IconButton } from '@/components/kiln/button';
import { CodeBlock } from '@/components/kiln/code-block';
import { CopyButton } from '@/components/kiln/copy-button';
import { EmptyState } from '@/components/kiln/empty-state';
import { SkeletonRows } from '@/components/kiln/skeleton';
import { StatusBadge } from '@/components/kiln/status';
import { Tag } from '@/components/kiln/tag';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ChevronDown, ChevronRight, SearchX, SquareArrowOutUpRight, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { formatDuration, getJson, HttpError } from '../lib';
import { type AttributeValue, type SpanDto, type TraceDto } from '../types';
import { BackendError } from './backend-state';

/** Categorical slots by span category (fixed order; see --chart-N in app.css). Errors always use --danger. */
const CATEGORY_COLORS: Record<string, string> = {
    request: 'var(--chart-1)',
    query: 'var(--chart-2)',
    job: 'var(--chart-3)',
    outgoing_request: 'var(--chart-4)',
    cache: 'var(--chart-5)',
};

export interface WaterfallRow {
    span: SpanDto;
    depth: number;
    offsetMs: number;
    childCount: number;
}

function nanos(value: string): bigint {
    try {
        return BigInt(value);
    } catch {
        return 0n;
    }
}

/** Depth-first (parent → children, by start time) ordering with offsets relative to the trace start. */
export function buildRows(spans: SpanDto[]): { rows: WaterfallRow[]; totalMs: number; startNs: bigint } {
    if (spans.length === 0) {
        return { rows: [], totalMs: 0, startNs: 0n };
    }

    const start = spans.reduce(
        (min, span) => (nanos(span.start_unix_nano) < min ? nanos(span.start_unix_nano) : min),
        nanos(spans[0].start_unix_nano),
    );
    const end = spans.reduce((max, span) => (nanos(span.end_unix_nano) > max ? nanos(span.end_unix_nano) : max), 0n);
    const ids = new Set(spans.map((span) => span.span_id));
    const children = new Map<string | null, SpanDto[]>();

    spans.forEach((span) => {
        // Orphans (parent not in this trace) are shown as roots.
        const parent = span.parent_span_id && ids.has(span.parent_span_id) ? span.parent_span_id : null;
        children.set(parent, [...(children.get(parent) ?? []), span]);
    });

    children.forEach((list) => list.sort((a, b) => (nanos(a.start_unix_nano) < nanos(b.start_unix_nano) ? -1 : 1)));

    const rows: WaterfallRow[] = [];
    const visited = new Set<string>();
    const walk = (parent: string | null, depth: number) => {
        (children.get(parent) ?? []).forEach((span) => {
            if (visited.has(span.span_id)) return;
            visited.add(span.span_id);
            rows.push({
                span,
                depth,
                offsetMs: Number(nanos(span.start_unix_nano) - start) / 1_000_000,
                childCount: children.get(span.span_id)?.length ?? 0,
            });
            walk(span.span_id, depth + 1);
        });
    };
    walk(null, 0);

    return { rows, totalMs: Math.max(Number(end - start) / 1_000_000, 0.001), startNs: start };
}

export function spanCategory(span: SpanDto): string {
    const type = span.attributes['kiln.event.type'] ?? span.attributes['kiln.timeline.phase'];

    return typeof type === 'string' ? type : span.kind.toLowerCase();
}

function barColor(span: SpanDto): string {
    if (span.status === 'error') return 'var(--danger)';

    return CATEGORY_COLORS[spanCategory(span)] ?? 'var(--text-faint)';
}

function AttributeList({ values, empty = 'None' }: { values: Record<string, AttributeValue>; empty?: string }) {
    const entries = Object.entries(values).sort(([a], [b]) => a.localeCompare(b));

    if (entries.length === 0) {
        return <p className="text-fg-faint text-xs">{empty}</p>;
    }

    return (
        <dl className="divide-border border-border divide-y rounded-md border text-xs">
            {entries.map(([key, value]) => (
                <div key={key} className="group grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-3 px-2.5 py-1.5">
                    <dt className="text-fg-muted truncate font-mono" title={key}>
                        {key}
                    </dt>
                    <dd className="text-fg flex min-w-0 items-start gap-1 font-mono break-all">
                        <span className="min-w-0 flex-1">{value === null ? 'null' : String(value)}</span>
                        <CopyButton value={value === null ? 'null' : String(value)} size="xs" className="opacity-0 group-hover:opacity-100" />
                    </dd>
                </div>
            ))}
        </dl>
    );
}

/** Span attributes panel: status, timing, exception events, attributes and resource. */
export function SpanDetails({ span, onClose, className }: { span: SpanDto; onClose?: () => void; className?: string }) {
    return (
        <aside
            aria-label="Span details"
            className={cn('border-border bg-surface-1 grid min-w-0 content-start gap-4 rounded-lg border p-4', className)}
        >
            <div className="flex items-start justify-between gap-2">
                <div className="grid min-w-0 gap-1">
                    <p className="text-fg truncate font-mono text-sm font-medium" title={span.name}>
                        {span.name}
                    </p>
                    <div className="flex flex-wrap items-center gap-1.5">
                        <StatusBadge status={span.status === 'error' ? 'error' : 'success'} label={span.status === 'error' ? 'Error' : 'OK'} />
                        <Tag>{spanCategory(span).replace(/_/g, ' ')}</Tag>
                        <Tag>{span.service}</Tag>
                        <span className="text-fg-muted tabular text-xs">{formatDuration(span.duration_ms)}</span>
                    </div>
                </div>
                {onClose && <IconButton size="sm" label="Close span details" icon={<X />} onClick={onClose} />}
            </div>
            <p className="text-fg-faint flex items-center gap-1 font-mono text-xs">
                span {span.span_id} <CopyButton value={span.span_id} size="xs" label="Copy span id" />
            </p>
            {span.status_message && <p className="text-danger text-sm">{span.status_message}</p>}
            {span.events.map((event, index) => (
                <div key={`${event.name}-${index}`} className="grid gap-1.5">
                    <p className="text-fg text-xs font-medium">
                        {event.name === 'exception' ? 'Exception' : `Event · ${event.name}`}
                        {event.name === 'exception' && typeof event.attributes['exception.type'] === 'string' && (
                            <span className="text-danger font-mono"> {event.attributes['exception.type']}</span>
                        )}
                    </p>
                    {event.name === 'exception' ? (
                        <>
                            {event.attributes['exception.message'] != null && (
                                <p className="text-fg text-sm">{String(event.attributes['exception.message'])}</p>
                            )}
                            {event.attributes['exception.stacktrace'] != null && (
                                <CodeBlock code={String(event.attributes['exception.stacktrace'])} maxHeight={240} />
                            )}
                        </>
                    ) : (
                        <AttributeList values={event.attributes} />
                    )}
                </div>
            ))}
            <div className="grid gap-1.5">
                <p className="text-fg text-xs font-medium">Attributes</p>
                <AttributeList values={span.attributes} />
            </div>
            <details className="group">
                <summary className="text-fg-muted hover:text-fg cursor-pointer text-xs font-medium">Resource</summary>
                <div className="pt-2">
                    <AttributeList values={span.resource} />
                </div>
            </details>
        </aside>
    );
}

interface WaterfallProps {
    spans: SpanDto[];
    /** Highlight a span (e.g. the one that raised an exception) and select it initially. */
    highlightSpanId?: string | null;
    /** Where the span details render: beside the waterfall (pages) or below it (narrow containers). */
    detailsPlacement?: 'side' | 'below';
}

/** Trace waterfall: one row per span (collapsible tree), bars positioned by offset and duration, span attributes panel. */
export function TraceWaterfall({ spans, highlightSpanId, detailsPlacement = 'side' }: WaterfallProps) {
    const { rows, totalMs } = useMemo(() => buildRows(spans), [spans]);
    const [selected, setSelected] = useState<string | null>(highlightSpanId ?? rows[0]?.span.span_id ?? null);
    const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
    const selectedSpan = rows.find((row) => row.span.span_id === selected)?.span ?? null;
    const ticks = [0, 0.25, 0.5, 0.75, 1];

    const visible = useMemo(() => {
        const hiddenBelow: number[] = [];
        const out: WaterfallRow[] = [];

        rows.forEach((row) => {
            while (hiddenBelow.length > 0 && row.depth <= hiddenBelow[hiddenBelow.length - 1]) hiddenBelow.pop();
            if (hiddenBelow.length > 0) return;
            out.push(row);
            if (collapsed.has(row.span.span_id)) hiddenBelow.push(row.depth);
        });

        return out;
    }, [rows, collapsed]);

    const toggle = useCallback(
        (id: string) =>
            setCollapsed((current) => {
                const next = new Set(current);
                if (next.has(id)) next.delete(id);
                else next.add(id);

                return next;
            }),
        [],
    );

    if (rows.length === 0) {
        return <EmptyState size="sm" icon={<SearchX />} title="This trace has no spans" />;
    }

    const categories = [...new Set(rows.map((row) => spanCategory(row.span)))].filter((category) => CATEGORY_COLORS[category]);

    return (
        <div className={cn('grid min-w-0 gap-4', detailsPlacement === 'side' && selectedSpan && 'xl:grid-cols-[minmax(0,1fr)_380px]')}>
            <div className="border-border bg-surface-1 min-w-0 overflow-hidden rounded-lg border">
                <div className="border-border flex flex-wrap items-center gap-x-4 gap-y-1 border-b px-3 py-2 text-xs">
                    <span className="text-fg-muted">
                        <span className="text-fg tabular font-medium">{rows.length}</span> spans ·{' '}
                        <span className="text-fg tabular font-medium">{formatDuration(totalMs)}</span>
                    </span>
                    <ul className="text-fg-muted flex flex-wrap items-center gap-3" aria-label="Legend">
                        {categories.map((category) => (
                            <li key={category} className="flex items-center gap-1.5">
                                <span className="size-2 rounded-full" style={{ background: CATEGORY_COLORS[category] }} aria-hidden />
                                {category.replace(/_/g, ' ')}
                            </li>
                        ))}
                        {rows.some((row) => row.span.status === 'error') && (
                            <li className="flex items-center gap-1.5">
                                <span className="bg-danger size-2 rounded-full" aria-hidden />
                                error
                            </li>
                        )}
                    </ul>
                </div>
                <div className="overflow-x-auto">
                    <div className="min-w-[640px] text-xs" role="table" aria-label="Trace timeline">
                        <div className="border-border text-fg-faint flex border-b py-1.5" role="row">
                            <div className="w-[38%] shrink-0 px-3" role="columnheader">
                                Span
                            </div>
                            <div className="relative mr-14 h-4 flex-1" role="columnheader">
                                {ticks.map((tick) => (
                                    <span
                                        key={tick}
                                        className="tabular absolute -translate-x-1/2 first:translate-x-0 last:-translate-x-full"
                                        style={{ left: `${tick * 100}%` }}
                                    >
                                        {formatDuration(totalMs * tick)}
                                    </span>
                                ))}
                            </div>
                        </div>
                        {visible.map(({ span, depth, offsetMs, childCount }) => {
                            const left = (offsetMs / totalMs) * 100;
                            const width = Math.max((span.duration_ms / totalMs) * 100, 0.4);
                            const isSelected = span.span_id === selected;
                            const isCollapsed = collapsed.has(span.span_id);

                            return (
                                <div
                                    key={span.span_id}
                                    role="row"
                                    aria-selected={isSelected}
                                    tabIndex={0}
                                    onClick={() => setSelected(isSelected ? null : span.span_id)}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter' || event.key === ' ') {
                                            event.preventDefault();
                                            setSelected(isSelected ? null : span.span_id);
                                        }
                                    }}
                                    className={cn(
                                        'hover:bg-surface-2 focus-visible:outline-primary relative flex h-7 cursor-pointer items-center transition-colors duration-150 focus-visible:outline-2 focus-visible:-outline-offset-2',
                                        isSelected && 'bg-surface-3 hover:bg-surface-3',
                                        highlightSpanId === span.span_id && 'shadow-[inset_2px_0_0_var(--danger)]',
                                    )}
                                >
                                    <div
                                        className="flex w-[38%] shrink-0 items-center gap-1 pr-2 pl-2"
                                        style={{ paddingLeft: `${8 + depth * 14}px` }}
                                        role="cell"
                                    >
                                        {childCount > 0 ? (
                                            <button
                                                type="button"
                                                className="text-fg-faint hover:text-fg rounded-sm"
                                                aria-label={isCollapsed ? `Expand ${childCount} child spans` : 'Collapse child spans'}
                                                aria-expanded={!isCollapsed}
                                                onClick={(event) => {
                                                    event.stopPropagation();
                                                    toggle(span.span_id);
                                                }}
                                            >
                                                {isCollapsed ? <ChevronRight className="size-3.5" /> : <ChevronDown className="size-3.5" />}
                                            </button>
                                        ) : (
                                            <span className="w-3.5 shrink-0" aria-hidden />
                                        )}
                                        <span className="size-2 shrink-0 rounded-full" style={{ background: barColor(span) }} aria-hidden />
                                        <span
                                            className={cn('truncate font-mono', span.status === 'error' ? 'text-danger' : 'text-fg')}
                                            title={span.name}
                                        >
                                            {span.name}
                                        </span>
                                        {isCollapsed && <span className="text-fg-faint tabular shrink-0">+{childCount}</span>}
                                    </div>
                                    <div className="relative mr-14 h-full flex-1" role="cell">
                                        {ticks.slice(1, -1).map((tick) => (
                                            <span
                                                key={tick}
                                                className="bg-border absolute inset-y-0 w-px"
                                                style={{ left: `${tick * 100}%` }}
                                                aria-hidden
                                            />
                                        ))}
                                        <div
                                            className="absolute top-1/2 h-2.5 -translate-y-1/2 rounded-sm"
                                            style={{ left: `${left}%`, width: `${Math.min(width, 100 - left)}%`, background: barColor(span) }}
                                        />
                                        <span
                                            className="text-fg-muted tabular absolute top-1/2 -translate-y-1/2 whitespace-nowrap"
                                            style={left + width > 78 ? { right: `${100 - left + 0.75}%` } : { left: `${left + width + 0.75}%` }}
                                        >
                                            {formatDuration(span.duration_ms)}
                                        </span>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>
            {selectedSpan && (
                <SpanDetails
                    span={selectedSpan}
                    onClose={() => setSelected(null)}
                    className={detailsPlacement === 'side' ? 'xl:sticky xl:top-16 xl:max-h-[calc(100vh-5rem)] xl:overflow-y-auto' : undefined}
                />
            )}
        </div>
    );
}

export type TraceLoadState =
    { status: 'loading' } | { status: 'loaded'; trace: TraceDto } | { status: 'missing' } | { status: 'error'; error: unknown };

/** Loads /telemetry/traces/{id}/data. */
export function useTrace(traceId: string): [TraceLoadState, () => void] {
    const [state, setState] = useState<TraceLoadState>({ status: 'loading' });
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        const controller = new AbortController();
        setState({ status: 'loading' });

        getJson<{ trace: TraceDto }>(`/telemetry/traces/${encodeURIComponent(traceId)}/data`, controller.signal)
            .then((body) => setState({ status: 'loaded', trace: body.trace }))
            .catch((error: unknown) => {
                if (controller.signal.aborted) return;
                setState(error instanceof HttpError && error.status === 404 ? { status: 'missing' } : { status: 'error', error });
            });

        return () => controller.abort();
    }, [traceId, attempt]);

    return [state, () => setAttempt((value) => value + 1)];
}

/** Loading / missing / unreachable states + the waterfall for one trace id. */
export function TraceView({
    traceId,
    highlightSpanId,
    detailsPlacement = 'side',
}: {
    traceId: string;
    highlightSpanId?: string | null;
    detailsPlacement?: 'side' | 'below';
}) {
    const [state, retry] = useTrace(traceId);

    if (state.status === 'loading') return <SkeletonRows rows={6} />;
    if (state.status === 'missing') {
        return (
            <EmptyState
                size="sm"
                icon={<SearchX />}
                title="Trace not available"
                description={`Trace ${traceId} was not sampled, is past retention, or belongs to another organization.`}
            />
        );
    }
    if (state.status === 'error') return <BackendError size="sm" backend="Tempo" error={state.error} onRetry={retry} />;

    return <TraceWaterfall spans={state.trace.spans} highlightSpanId={highlightSpanId} detailsPlacement={detailsPlacement} />;
}

/** Linked trace section for detail pages (e.g. an issue's last occurrence). */
export function TraceTimelineCard({
    traceId,
    title = 'Trace',
    highlightSpanId,
}: {
    traceId: string;
    title?: string;
    highlightSpanId?: string | null;
}) {
    return (
        <section className="grid gap-3" aria-label={title}>
            <div className="flex items-center justify-between gap-2">
                <h2 className="text-fg text-sm font-medium">{title}</h2>
                <Button asChild variant="ghost" size="sm">
                    <Link href={`/observability/traces/${traceId}`}>
                        <SquareArrowOutUpRight /> Open trace
                    </Link>
                </Button>
            </div>
            <TraceView traceId={traceId} highlightSpanId={highlightSpanId} detailsPlacement="below" />
        </section>
    );
}
