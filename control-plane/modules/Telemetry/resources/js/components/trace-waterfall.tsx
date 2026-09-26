import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ExternalLink, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { formatDuration, getJson, HttpError } from '../lib';
import { type AttributeValue, type SpanDto, type TraceDto } from '../types';

const EVENT_COLORS: Record<string, string> = {
    request: 'bg-blue-500',
    query: 'bg-amber-500',
    job: 'bg-violet-500',
    outgoing_request: 'bg-cyan-500',
    cache: 'bg-emerald-500',
    mail: 'bg-pink-500',
    notification: 'bg-pink-400',
    command: 'bg-slate-500',
    scheduled_task: 'bg-slate-600',
};

const PHASE_COLORS: Record<string, string> = {
    bootstrap: 'bg-zinc-300 dark:bg-zinc-600',
    middleware: 'bg-zinc-400 dark:bg-zinc-500',
    controller: 'bg-indigo-400',
    response: 'bg-zinc-500 dark:bg-zinc-400',
};

export interface WaterfallRow {
    span: SpanDto;
    depth: number;
    offsetMs: number;
}

function nanos(value: string): bigint {
    try {
        return BigInt(value);
    } catch {
        return 0n;
    }
}

/** Depth-first (parent → children, by start time) ordering with offsets relative to the trace start. */
export function buildRows(spans: SpanDto[]): { rows: WaterfallRow[]; totalMs: number } {
    if (spans.length === 0) {
        return { rows: [], totalMs: 0 };
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
            rows.push({ span, depth, offsetMs: Number(nanos(span.start_unix_nano) - start) / 1_000_000 });
            walk(span.span_id, depth + 1);
        });
    };
    walk(null, 0);

    return { rows, totalMs: Math.max(Number(end - start) / 1_000_000, 0.001) };
}

function barColor(span: SpanDto): string {
    if (span.status === 'error') return 'bg-red-500';

    const type = span.attributes['kiln.event.type'];
    if (typeof type === 'string' && EVENT_COLORS[type]) return EVENT_COLORS[type];

    const phase = span.attributes['kiln.timeline.phase'];
    if (typeof phase === 'string' && PHASE_COLORS[phase]) return PHASE_COLORS[phase];

    return 'bg-sky-400';
}

function spanLabel(span: SpanDto): string {
    const type = span.attributes['kiln.event.type'] ?? span.attributes['kiln.timeline.phase'];

    return typeof type === 'string' ? type.replace('_', ' ') : span.kind;
}

function AttributeTable({ values }: { values: Record<string, AttributeValue> }) {
    const entries = Object.entries(values);

    if (entries.length === 0) {
        return <p className="text-muted-foreground text-xs">None</p>;
    }

    return (
        <dl className="grid grid-cols-[minmax(8rem,auto)_1fr] gap-x-3 gap-y-1 text-xs">
            {entries.map(([key, value]) => (
                <div key={key} className="contents">
                    <dt className="text-muted-foreground truncate font-mono">{key}</dt>
                    <dd className="font-mono break-all whitespace-pre-wrap">{value === null ? 'null' : String(value)}</dd>
                </div>
            ))}
        </dl>
    );
}

export function SpanDetails({ span, onClose }: { span: SpanDto; onClose?: () => void }) {
    return (
        <div className="bg-muted/30 space-y-4 rounded-md border p-4">
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="truncate font-medium">{span.name}</p>
                    <p className="text-muted-foreground text-xs">
                        {span.service} · {span.kind} · {formatDuration(span.duration_ms)} · span {span.span_id}
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <Badge variant={span.status === 'error' ? 'destructive' : 'secondary'}>{span.status}</Badge>
                    {onClose && (
                        <Button variant="ghost" size="icon" onClick={onClose} aria-label="Close span details">
                            <X />
                        </Button>
                    )}
                </div>
            </div>
            {span.status_message && <p className="text-destructive text-sm">{span.status_message}</p>}
            {span.events.map((event, index) => (
                <div key={`${event.name}-${index}`} className="space-y-1">
                    <p className="text-sm font-medium">
                        Event: {event.name}
                        {event.name === 'exception' && typeof event.attributes['exception.type'] === 'string' && (
                            <span className="text-destructive"> · {event.attributes['exception.type']}</span>
                        )}
                    </p>
                    {event.name === 'exception' ? (
                        <>
                            {event.attributes['exception.message'] != null && (
                                <p className="text-sm">{String(event.attributes['exception.message'])}</p>
                            )}
                            {event.attributes['exception.stacktrace'] != null && (
                                <pre className="bg-background max-h-72 overflow-auto rounded border p-2 text-xs">
                                    {String(event.attributes['exception.stacktrace'])}
                                </pre>
                            )}
                        </>
                    ) : (
                        <AttributeTable values={event.attributes} />
                    )}
                </div>
            ))}
            <div className="space-y-1">
                <p className="text-sm font-medium">Attributes</p>
                <AttributeTable values={span.attributes} />
            </div>
            <details>
                <summary className="cursor-pointer text-sm font-medium">Resource</summary>
                <div className="pt-2">
                    <AttributeTable values={span.resource} />
                </div>
            </details>
        </div>
    );
}

interface WaterfallProps {
    spans: SpanDto[];
    onSelect?: (span: SpanDto) => void;
    /** Highlight a span (e.g. the one that raised an exception). */
    highlightSpanId?: string | null;
}

/** Request timeline: one row per span, bars positioned by start offset and duration. */
export function TraceWaterfall({ spans, onSelect, highlightSpanId }: WaterfallProps) {
    const { rows, totalMs } = useMemo(() => buildRows(spans), [spans]);
    const [selected, setSelected] = useState<string | null>(highlightSpanId ?? null);
    const selectedSpan = rows.find((row) => row.span.span_id === selected)?.span ?? null;
    const ticks = [0, 0.25, 0.5, 0.75, 1];

    if (rows.length === 0) {
        return <p className="text-muted-foreground py-6 text-center text-sm">This trace has no spans.</p>;
    }

    return (
        <div className="space-y-3">
            <div className="overflow-x-auto">
                <div className="min-w-[640px] text-xs" role="table" aria-label="Trace timeline">
                    <div className="text-muted-foreground flex border-b pb-1" role="row">
                        <div className="w-2/5 shrink-0 pr-2" role="columnheader">
                            Span
                        </div>
                        <div className="relative h-4 flex-1" role="columnheader">
                            {ticks.map((tick) => (
                                <span
                                    key={tick}
                                    className="absolute -translate-x-1/2 first:translate-x-0 last:-translate-x-full"
                                    style={{ left: `${tick * 100}%` }}
                                >
                                    {formatDuration(totalMs * tick)}
                                </span>
                            ))}
                        </div>
                    </div>
                    {rows.map(({ span, depth, offsetMs }) => {
                        const left = (offsetMs / totalMs) * 100;
                        const width = Math.max((span.duration_ms / totalMs) * 100, 0.3);
                        const isSelected = span.span_id === selected;

                        return (
                            <button
                                type="button"
                                key={span.span_id}
                                role="row"
                                onClick={() => {
                                    setSelected(isSelected ? null : span.span_id);
                                    onSelect?.(span);
                                }}
                                className={cn(
                                    'hover:bg-muted/60 flex w-full items-center border-b border-dashed py-1 text-left',
                                    isSelected && 'bg-muted',
                                    highlightSpanId === span.span_id && 'ring-destructive/60 ring-1',
                                )}
                            >
                                <div className="flex w-2/5 shrink-0 items-center gap-1 pr-2" style={{ paddingLeft: `${depth * 12}px` }} role="cell">
                                    <span className={cn('size-2 shrink-0 rounded-full', barColor(span))} />
                                    <span className="text-muted-foreground shrink-0">{spanLabel(span)}</span>
                                    <span className="truncate font-mono" title={span.name}>
                                        {span.name}
                                    </span>
                                </div>
                                <div className="relative h-4 flex-1" role="cell">
                                    <div
                                        className={cn('absolute top-0.5 h-3 rounded-sm', barColor(span))}
                                        style={{ left: `${left}%`, width: `${Math.min(width, 100 - left)}%` }}
                                    />
                                    <span
                                        className="text-muted-foreground absolute top-0 whitespace-nowrap"
                                        style={left + width > 80 ? { right: `${100 - left + 0.5}%` } : { left: `${left + width + 0.5}%` }}
                                    >
                                        {formatDuration(span.duration_ms)}
                                    </span>
                                </div>
                            </button>
                        );
                    })}
                </div>
            </div>
            {selectedSpan && <SpanDetails span={selectedSpan} onClose={() => setSelected(null)} />}
        </div>
    );
}

type LoadState = { status: 'loading' } | { status: 'loaded'; trace: TraceDto } | { status: 'missing' } | { status: 'error'; message: string };

/** Fetches /telemetry/traces/{id}/data and renders the waterfall (used by Insights issue pages). */
export function TraceTimelineCard({
    traceId,
    title = 'Trace timeline',
    highlightSpanId,
}: {
    traceId: string;
    title?: string;
    highlightSpanId?: string | null;
}) {
    const [state, setState] = useState<LoadState>({ status: 'loading' });

    useEffect(() => {
        const controller = new AbortController();
        setState({ status: 'loading' });

        getJson<{ trace: TraceDto }>(`/telemetry/traces/${encodeURIComponent(traceId)}/data`, controller.signal)
            .then((body) => setState({ status: 'loaded', trace: body.trace }))
            .catch((error: unknown) => {
                if (controller.signal.aborted) return;
                if (error instanceof HttpError && error.status === 404) {
                    setState({ status: 'missing' });
                } else {
                    setState({ status: 'error', message: error instanceof Error ? error.message : 'Failed to load the trace' });
                }
            });

        return () => controller.abort();
    }, [traceId]);

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between gap-2">
                <CardTitle>{title}</CardTitle>
                <Link href={`/telemetry/traces/${traceId}`} className="text-muted-foreground inline-flex items-center gap-1 text-xs hover:underline">
                    Open trace <ExternalLink className="size-3" />
                </Link>
            </CardHeader>
            <CardContent>
                {state.status === 'loading' && (
                    <div className="space-y-2">
                        <Skeleton className="h-4 w-full" />
                        <Skeleton className="h-4 w-4/5" />
                        <Skeleton className="h-4 w-3/5" />
                    </div>
                )}
                {state.status === 'missing' && (
                    <p className="text-muted-foreground text-sm">Trace {traceId} is not available (not sampled, or past retention).</p>
                )}
                {state.status === 'error' && <p className="text-destructive text-sm">{state.message}</p>}
                {state.status === 'loaded' && <TraceWaterfall spans={state.trace.spans} highlightSpanId={highlightSpanId} />}
            </CardContent>
        </Card>
    );
}
