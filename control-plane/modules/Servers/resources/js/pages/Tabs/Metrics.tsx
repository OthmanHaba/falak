import { Button } from '@/components/kiln/button';
import { EmptyState } from '@/components/kiln/empty-state';
import { MetricChart, type MetricPoint } from '@/components/kiln/metric-chart';
import ServerLayout, { type ServerHeader } from '@/layouts/server-layout';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Activity, ExternalLink, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { formatBytes, requestJson } from '../../components/server-ui';
import { type MetricSample } from '../../types';

interface Props {
    server: ServerHeader;
    capacity: { cpus: number | null; memory_bytes: number | null; disk_bytes: number | null };
    ranges: string[];
    /** The user may read the Telemetry backend (network / disk I/O charts). */
    telemetry: boolean;
}

interface SeriesDto {
    labels: Record<string, string>;
    points: [number, number | null][];
}

interface TelemetryResponse {
    charts: Partial<Record<'network' | 'disk_io', SeriesDto[]>>;
}

const percentFormat = (value: number) => `${value.toFixed(value < 10 ? 1 : 0)}%`;
const bytesPerSecond = (value: number) => `${formatBytes(value)}/s`;

/** Merge per-direction series (receive/transmit, read/write) into chart points keyed by label. */
function mergeSeries(series: SeriesDto[] | undefined, label: string): { data: MetricPoint[]; keys: { key: string; label: string }[] } {
    if (!series || series.length === 0) return { data: [], keys: [] };
    const byTime = new Map<number, MetricPoint>();
    const keys = series.map((item, index) => {
        const name = item.labels[label] ?? `series ${index + 1}`;
        item.points.forEach(([t, value]) => {
            const point = byTime.get(t) ?? { t };
            point[name] = value;
            byTime.set(t, point);
        });

        return { key: name, label: name.charAt(0).toUpperCase() + name.slice(1) };
    });

    return { data: [...byTime.values()].sort((a, b) => Number(a.t) - Number(b.t)), keys };
}

export default function Metrics({ server, capacity, ranges, telemetry }: Props) {
    const [range, setRange] = useState(ranges[0] ?? '1h');
    const [samples, setSamples] = useState<MetricSample[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);
    const [detail, setDetail] = useState<TelemetryResponse | null>(null);
    const [detailState, setDetailState] = useState<'idle' | 'loading' | 'unavailable'>('idle');

    const load = useCallback(
        async (next: string, quiet = false) => {
            if (!quiet) setLoading(true);
            try {
                const body = await requestJson<{ data: MetricSample[] }>(`/servers/${server.id}/metrics?range=${encodeURIComponent(next)}`);
                setSamples(body.data);
                setError(null);
            } catch (e) {
                setError(e instanceof Error ? e.message : 'Could not load metrics');
            } finally {
                setLoading(false);
            }
        },
        [server.id],
    );

    const loadDetail = useCallback(
        async (next: string) => {
            if (!telemetry) return;
            setDetailState('loading');
            try {
                setDetail(await requestJson<TelemetryResponse>(`/telemetry/servers/${server.id}/metrics/data?range=${encodeURIComponent(next)}`));
                setDetailState('idle');
            } catch {
                setDetail(null);
                setDetailState('unavailable');
            }
        },
        [server.id, telemetry],
    );

    useEffect(() => {
        void load(range);
        void loadDetail(range);
        // Live: refresh quietly every 30s.
        const timer = window.setInterval(() => void load(range, true), 30_000);

        return () => window.clearInterval(timer);
    }, [range, load, loadDetail]);

    const points = useMemo(
        () =>
            (samples ?? []).map((sample) => ({
                t: sample.at,
                cpu: sample.cpu_percent,
                memory: capacity.memory_bytes ? (sample.memory_used_bytes / capacity.memory_bytes) * 100 : null,
                disk: capacity.disk_bytes ? (sample.disk_used_bytes / capacity.disk_bytes) * 100 : null,
                load1: sample.load1,
                load5: sample.load5,
                load15: sample.load15,
            })),
        [samples, capacity.memory_bytes, capacity.disk_bytes],
    );

    const last = points[points.length - 1];
    const network = useMemo(() => mergeSeries(detail?.charts.network, 'network_io_direction'), [detail]);
    const diskIo = useMemo(() => mergeSeries(detail?.charts.disk_io, 'disk_io_direction'), [detail]);
    const noAgent = !server.agent;

    const rangePicker = (
        <div role="radiogroup" aria-label="Time range" className="border-border bg-surface-1 flex items-center rounded-md border p-0.5">
            {ranges.map((item) => (
                <button
                    key={item}
                    type="button"
                    role="radio"
                    aria-checked={range === item}
                    onClick={() => setRange(item)}
                    className={cn(
                        'h-6 rounded-sm px-2 text-xs font-medium transition-colors duration-150',
                        range === item ? 'bg-surface-3 text-fg' : 'text-fg-muted hover:text-fg',
                    )}
                >
                    {item}
                </button>
            ))}
        </div>
    );

    return (
        <ServerLayout
            server={server}
            tab="metrics"
            actions={
                <>
                    {rangePicker}
                    <Button variant="ghost" size="sm" icon={<RefreshCw />} onClick={() => void load(range)} loading={loading && samples !== null}>
                        Refresh
                    </Button>
                </>
            }
        >
            {noAgent ? (
                <EmptyState
                    icon={<Activity />}
                    title="No metrics yet"
                    description="The agent reports CPU, memory, disk and load with every heartbeat. Metrics appear here once it has connected."
                    action={
                        <Button variant="secondary" size="sm" asChild>
                            <Link href={`/servers/${server.id}`}>Go to overview</Link>
                        </Button>
                    }
                />
            ) : (
                <>
                    {error && (
                        <p role="alert" className="border-danger/40 bg-danger-soft text-danger rounded-lg border px-4 py-3 text-sm">
                            {error}
                        </p>
                    )}
                    <div className="grid gap-4 lg:grid-cols-2">
                        <MetricChart
                            title="CPU"
                            type="area"
                            data={points}
                            series={[{ key: 'cpu', label: 'CPU' }]}
                            format={percentFormat}
                            value={last?.cpu != null ? percentFormat(last.cpu) : undefined}
                            loading={loading && samples === null}
                        />
                        <MetricChart
                            title="Memory"
                            type="area"
                            data={points}
                            series={[{ key: 'memory', label: 'Memory' }]}
                            format={percentFormat}
                            value={last?.memory != null ? `${percentFormat(last.memory)} of ${formatBytes(capacity.memory_bytes)}` : undefined}
                            loading={loading && samples === null}
                        />
                        <MetricChart
                            title="Disk"
                            data={points}
                            series={[{ key: 'disk', label: 'Disk used' }]}
                            format={percentFormat}
                            value={last?.disk != null ? `${percentFormat(last.disk)} of ${formatBytes(capacity.disk_bytes)}` : undefined}
                            loading={loading && samples === null}
                        />
                        <MetricChart
                            title="Load average"
                            data={points}
                            series={[
                                { key: 'load1', label: '1m' },
                                { key: 'load5', label: '5m' },
                                { key: 'load15', label: '15m' },
                            ]}
                            format={(value) => value.toFixed(2)}
                            value={last ? `${last.load1.toFixed(2)}${capacity.cpus ? ` / ${capacity.cpus} vCPU` : ''}` : undefined}
                            loading={loading && samples === null}
                        />
                    </div>

                    {telemetry && (
                        <div className="grid gap-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="grid gap-0.5">
                                    <h2 className="text-fg text-base font-medium">Network & disk I/O</h2>
                                    <p className="text-fg-muted text-sm">From the telemetry backend (OpenTelemetry host metrics).</p>
                                </div>
                                <Button variant="ghost" size="sm" asChild>
                                    <Link href={`/telemetry/servers/${server.id}/metrics`}>
                                        Open in Telemetry <ExternalLink aria-hidden />
                                    </Link>
                                </Button>
                            </div>
                            {detailState === 'unavailable' ? (
                                <EmptyState
                                    size="sm"
                                    icon={<Activity />}
                                    title="Telemetry backend unavailable"
                                    description="Network and disk I/O charts need the metrics backend (VictoriaMetrics / Mimir) configured in Observability settings."
                                />
                            ) : (
                                <div className="grid gap-4 lg:grid-cols-2">
                                    <MetricChart
                                        title="Network"
                                        data={network.data}
                                        series={network.keys}
                                        format={bytesPerSecond}
                                        loading={detailState === 'loading' && detail === null}
                                    />
                                    <MetricChart
                                        title="Disk I/O"
                                        data={diskIo.data}
                                        series={diskIo.keys}
                                        format={bytesPerSecond}
                                        loading={detailState === 'loading' && detail === null}
                                    />
                                </div>
                            )}
                        </div>
                    )}
                </>
            )}
        </ServerLayout>
    );
}
