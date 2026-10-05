import { IconButton } from '@/components/falak/button';
import { EmptyState } from '@/components/falak/empty-state';
import { MetricChart, type MetricPoint } from '@/components/falak/metric-chart';
import { Segmented } from '@/components/falak/segmented';
import { Skeleton } from '@/components/falak/skeleton';
import { format } from 'date-fns';
import { RotateCw, TriangleAlert } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { BackendError, NotConfigured } from '../components/backend-state';
import { useSiteTelemetryContext } from '../components/site-context';
import { formatDuration, getJson } from '../lib';
import { type MetricSeriesDto } from '../types';

/** GET /telemetry/sites/{siteId}/metrics/data */
interface SiteMetricsResponse {
    range: string;
    step: number;
    servers: { id: string; name: string }[];
    charts: Partial<Record<'requests' | 'errors' | 'p95' | 'cpu' | 'memory', MetricSeriesDto[]>>;
    errors: Record<string, string>;
}

const RANGES = ['1h', '6h', '24h', '7d'] as const;
type Range = (typeof RANGES)[number];
const REFRESH_MS = 30_000;

/** One series → points keyed `value`. */
function single(series: MetricSeriesDto[] | undefined): MetricPoint[] {
    return (series?.[0]?.points ?? []).map(([t, value]) => ({ t, value }));
}

/** Several series (one per server) merged by timestamp, keyed by server id. */
function perServer(series: MetricSeriesDto[] | undefined): MetricPoint[] {
    const byTime = new Map<number, MetricPoint>();

    (series ?? []).forEach((item) => {
        const key = (item.labels.falak_server_id ?? 'all').toLowerCase();
        item.points.forEach(([t, value]) => {
            const point = byTime.get(t) ?? { t };
            point[key] = value;
            byTime.set(t, point);
        });
    });

    return [...byTime.values()].sort((a, b) => Number(a.t) - Number(b.t));
}

function latest(points: MetricPoint[], key = 'value'): number | null {
    for (let index = points.length - 1; index >= 0; index--) {
        const value = points[index][key];
        if (typeof value === 'number') return value;
    }

    return null;
}

export interface SiteMetricsProps {
    siteId: string;
}

/**
 * Service panel → Metrics tab (docs/UI_DESIGN.md §5.1): requests, errors, p95 latency (span metrics) and CPU/memory
 * of each server the site runs on. Refreshes every 30s.
 */
export default function SiteMetrics({ siteId }: SiteMetricsProps) {
    const [context, retryContext] = useSiteTelemetryContext(siteId);
    const [range, setRange] = useState<Range>('1h');
    const [data, setData] = useState<SiteMetricsResponse | null>(null);
    const [error, setError] = useState<unknown>(null);
    const [loading, setLoading] = useState(true);
    const configured = context.status === 'ready' && context.data.configured.metrics;

    const load = useCallback(
        async (signal?: AbortSignal) => {
            setLoading(true);
            try {
                const body = await getJson<SiteMetricsResponse>(`/telemetry/sites/${encodeURIComponent(siteId)}/metrics/data?range=${range}`, signal);
                setData(body);
                setError(null);
            } catch (caught) {
                if (!signal?.aborted) setError(caught);
            } finally {
                if (!signal?.aborted) setLoading(false);
            }
        },
        [siteId, range],
    );

    useEffect(() => {
        if (!configured) return;
        const controller = new AbortController();
        void load(controller.signal);
        const timer = window.setInterval(() => void load(), REFRESH_MS);

        return () => {
            controller.abort();
            window.clearInterval(timer);
        };
    }, [configured, load]);

    const servers = useMemo(
        () =>
            (data?.servers ?? (context.status === 'ready' ? context.data.servers : [])).map((server) => ({
                key: server.id.toLowerCase(),
                label: server.name,
            })),
        [data, context],
    );
    const requests = useMemo(() => single(data?.charts.requests), [data]);
    const errors = useMemo(() => single(data?.charts.errors), [data]);
    const p95 = useMemo(() => single(data?.charts.p95), [data]);
    const cpu = useMemo(() => perServer(data?.charts.cpu), [data]);
    const memory = useMemo(() => perServer(data?.charts.memory), [data]);

    if (context.status === 'loading') {
        return (
            <div className="grid gap-3 sm:grid-cols-2">
                {Array.from({ length: 4 }, (_, index) => (
                    <Skeleton key={index} className="h-56" />
                ))}
            </div>
        );
    }
    if (context.status === 'error') return <BackendError size="sm" backend="metrics backend" error={context.error} onRetry={retryContext} />;
    if (!configured) return <NotConfigured size="sm" backend="metrics backend" />;

    const timeFormat = (value: number | string) =>
        format(new Date(typeof value === 'number' ? value * 1000 : value), range === '7d' ? 'MMM d' : 'HH:mm');
    const perChartError = (key: string) => data?.errors[key];
    const percent = (value: number) => `${value.toFixed(0)}%`;
    const rate = (value: number) => (value >= 100 ? value.toFixed(0) : value >= 1 ? value.toFixed(1) : value.toFixed(2));
    const empty = (key: string) =>
        perChartError(key) ? (
            <span className="text-danger flex items-center gap-1.5 text-xs">
                <TriangleAlert className="size-3.5" /> {perChartError(key)}
            </span>
        ) : (
            'No data for this range.'
        );

    return (
        <div className="grid min-w-0 gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-fg-muted text-xs">
                    {servers.length === 0 ? 'No servers' : `${servers.length} server${servers.length === 1 ? '' : 's'}`} · refreshes every 30s
                </p>
                <div className="flex items-center gap-1">
                    <Segmented label="Time range" value={range} onValueChange={setRange} options={RANGES.map((value) => ({ value, label: value }))} />
                    <IconButton size="sm" label="Refresh" icon={<RotateCw />} onClick={() => void load()} loading={loading && data !== null} />
                </div>
            </div>
            {error !== null && data === null ? (
                <BackendError size="sm" backend="metrics backend" error={error} onRetry={() => void load()} />
            ) : (
                <div className="grid gap-3 lg:grid-cols-2">
                    <MetricChart
                        title="Requests / s"
                        type="area"
                        data={requests}
                        series={[{ key: 'value', label: 'Requests / s' }]}
                        value={latest(requests) !== null ? rate(latest(requests)!) : undefined}
                        format={rate}
                        loading={loading && data === null}
                        timeFormat={timeFormat}
                        emptyText={empty('requests')}
                    />
                    <MetricChart
                        title="p95 latency"
                        data={p95}
                        series={[{ key: 'value', label: 'p95' }]}
                        value={latest(p95) !== null ? formatDuration(latest(p95)!) : undefined}
                        format={(value) => formatDuration(value)}
                        loading={loading && data === null}
                        timeFormat={timeFormat}
                        emptyText={empty('p95')}
                    />
                    <MetricChart
                        title="5xx errors / s"
                        type="bar"
                        data={errors}
                        series={[{ key: 'value', label: '5xx / s' }]}
                        value={latest(errors) !== null ? rate(latest(errors)!) : undefined}
                        format={rate}
                        loading={loading && data === null}
                        timeFormat={timeFormat}
                        emptyText={empty('errors')}
                    />
                    {servers.length === 0 ? (
                        <EmptyState size="sm" title="No servers" description="Add this site to a server to see CPU and memory." />
                    ) : (
                        <>
                            <MetricChart
                                title="CPU"
                                data={cpu}
                                series={servers}
                                format={percent}
                                loading={loading && data === null}
                                timeFormat={timeFormat}
                                emptyText={empty('cpu')}
                            />
                            <MetricChart
                                title="Memory"
                                data={memory}
                                series={servers}
                                format={percent}
                                loading={loading && data === null}
                                timeFormat={timeFormat}
                                emptyText={empty('memory')}
                            />
                        </>
                    )}
                </div>
            )}
        </div>
    );
}
