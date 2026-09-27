import { Button } from '@/components/kiln/button';
import { DataTable, type DataTableColumn } from '@/components/kiln/data-table';
import { Field } from '@/components/kiln/field';
import { Input } from '@/components/kiln/input';
import { RelativeTime } from '@/components/kiln/relative-time';
import { Segmented } from '@/components/kiln/segmented';
import { Select } from '@/components/kiln/select';
import ObservabilityLayout from '@/layouts/observability-layout';
import { router } from '@inertiajs/react';
import { Activity, Search } from 'lucide-react';
import { useCallback, useEffect, useState, type FormEventHandler } from 'react';
import { BackendError, NotConfigured } from '../components/backend-state';
import { formatDuration, getJson, queryString } from '../lib';
import { type TraceSummaryDto } from '../types';

interface Filters {
    site_id?: string;
    service?: string;
    name?: string;
    status?: string;
    min_duration_ms?: string;
    range?: string;
    from?: string;
    to?: string;
}

interface Props {
    filters: Filters;
    sites: { id: string; name: string }[];
    configured: boolean;
}

const RANGES = ['15m', '1h', '6h', '24h', '7d'] as const;
const ANY = '__any__';

type SearchState = { status: 'idle' } | { status: 'loading' } | { status: 'loaded'; traces: TraceSummaryDto[] } | { status: 'error'; error: unknown };

function startedAt(trace: TraceSummaryDto): number {
    try {
        return Number(BigInt(trace.start_unix_nano) / 1_000_000n);
    } catch {
        return 0;
    }
}

export default function Traces({ filters: initial, sites, configured }: Props) {
    const [filters, setFilters] = useState<Filters>({ range: '1h', status: 'any', ...initial });
    const [state, setState] = useState<SearchState>({ status: 'idle' });

    const run = useCallback(async (current: Filters) => {
        setState({ status: 'loading' });
        const query = queryString({ ...current, status: current.status === 'any' ? undefined : current.status });
        window.history.replaceState(window.history.state, '', `/observability/traces${query}`);

        try {
            const body = await getJson<{ traces: TraceSummaryDto[] }>(`/telemetry/traces/search${query}`);
            setState({ status: 'loaded', traces: body.traces });
        } catch (error) {
            setState({ status: 'error', error });
        }
    }, []);

    useEffect(() => {
        if (configured) void run({ range: '1h', status: 'any', ...initial });
    }, [configured, initial, run]);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        void run(filters);
    };

    const update = (patch: Partial<Filters>, immediate = false) => {
        const next = { ...filters, ...patch };
        setFilters(next);
        if (immediate && configured) void run(next);
    };

    const traces = state.status === 'loaded' ? state.traces : [];
    const longest = Math.max(1, ...traces.map((trace) => trace.duration_ms));
    const siteName = (id: string | undefined) => sites.find((site) => site.id.toLowerCase() === id?.toLowerCase())?.name;

    const columns: DataTableColumn<TraceSummaryDto>[] = [
        {
            id: 'root',
            header: 'Root span',
            cell: (trace) => (
                <span className="grid min-w-0">
                    <span className="text-fg truncate font-mono text-xs">{trace.root_name ?? '(unknown root)'}</span>
                    <span className="text-fg-faint text-2xs truncate font-mono">{trace.trace_id}</span>
                </span>
            ),
            sortValue: (trace) => trace.root_name,
        },
        {
            id: 'service',
            header: 'Service',
            cell: (trace) => <span className="text-fg-muted">{trace.root_service ?? '—'}</span>,
            sortValue: (trace) => trace.root_service,
            hideOnMobile: true,
        },
        {
            id: 'duration',
            header: 'Duration',
            width: '28%',
            cell: (trace) => (
                <span className="flex items-center gap-2">
                    <span className="bg-surface-2 relative hidden h-1.5 flex-1 overflow-hidden rounded-full sm:block" aria-hidden>
                        <span
                            className="bg-chart-1 absolute inset-y-0 left-0 rounded-full"
                            style={{ width: `${Math.max(2, (trace.duration_ms / longest) * 100)}%` }}
                        />
                    </span>
                    <span className="tabular text-fg w-16 text-right text-xs">{formatDuration(trace.duration_ms)}</span>
                </span>
            ),
            sortValue: (trace) => trace.duration_ms,
        },
        {
            id: 'spans',
            header: 'Matched',
            align: 'right',
            cell: (trace) => trace.matched_spans,
            sortValue: (trace) => trace.matched_spans,
            hideOnMobile: true,
        },
        {
            id: 'started',
            header: 'Started',
            align: 'right',
            cell: (trace) => <RelativeTime value={startedAt(trace)} className="text-fg-muted text-xs" />,
            sortValue: (trace) => startedAt(trace),
        },
    ];

    return (
        <ObservabilityLayout tab="traces">
            <form
                onSubmit={submit}
                className="border-border bg-surface-1 grid gap-3 rounded-lg border p-3 sm:grid-cols-2 lg:grid-cols-[1.2fr_1fr_1.4fr_0.8fr_auto] lg:items-end"
            >
                <Field label="Site">
                    <Select
                        value={filters.site_id ?? ANY}
                        onValueChange={(value) => update({ site_id: value === ANY ? undefined : value }, true)}
                        options={[{ value: ANY, label: 'All sites' }, ...sites.map((site) => ({ value: site.id, label: site.name }))]}
                    />
                </Field>
                <Field label="Service">
                    <Input
                        value={filters.service ?? ''}
                        onChange={(event) => update({ service: event.target.value || undefined })}
                        placeholder="service.name"
                        mono
                    />
                </Field>
                <Field label="Span name">
                    <Input
                        value={filters.name ?? ''}
                        onChange={(event) => update({ name: event.target.value || undefined })}
                        placeholder="GET /orders/{order}"
                        mono
                    />
                </Field>
                <Field label="Min duration">
                    <Input
                        type="number"
                        min={0}
                        value={filters.min_duration_ms ?? ''}
                        onChange={(event) => update({ min_duration_ms: event.target.value || undefined })}
                        suffix={<span className="text-xs">ms</span>}
                    />
                </Field>
                <Button type="submit" variant="primary" icon={<Search />} loading={state.status === 'loading'} disabled={!configured}>
                    Search
                </Button>
                <div className="flex flex-wrap items-center gap-2 sm:col-span-2 lg:col-span-5">
                    <Segmented
                        label="Status"
                        value={filters.status === 'error' ? 'error' : 'any'}
                        onValueChange={(status) => update({ status }, true)}
                        options={[
                            { value: 'any', label: 'All traces' },
                            { value: 'error', label: 'Errors only' },
                        ]}
                    />
                    <Segmented
                        label="Time range"
                        value={(filters.range as (typeof RANGES)[number]) ?? '1h'}
                        onValueChange={(range) => update({ range, from: undefined, to: undefined }, true)}
                        options={RANGES.map((range) => ({ value: range, label: range }))}
                    />
                    {filters.site_id && <span className="text-fg-muted text-xs">Site: {siteName(filters.site_id) ?? filters.site_id}</span>}
                </div>
            </form>

            {!configured ? (
                <NotConfigured backend="Tempo" />
            ) : state.status === 'error' ? (
                <BackendError backend="Tempo" error={state.error} onRetry={() => void run(filters)} />
            ) : (
                <DataTable
                    label="Traces"
                    columns={columns}
                    rows={traces}
                    rowKey={(trace) => trace.trace_id}
                    loading={state.status === 'loading' || state.status === 'idle'}
                    onRowClick={(trace) => router.visit(`/observability/traces/${trace.trace_id}`)}
                    empty={{
                        icon: <Activity />,
                        title: 'No traces match',
                        description:
                            'Widen the time range or loosen the filters. Traces are recorded for requests, jobs and commands of instrumented sites.',
                    }}
                />
            )}
        </ObservabilityLayout>
    );
}
