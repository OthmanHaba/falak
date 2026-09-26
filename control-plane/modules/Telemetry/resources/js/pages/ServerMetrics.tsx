import Heading from '@/components/heading';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ExternalLink, RefreshCw, ScrollText } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { MetricChart } from '../components/metric-chart';
import { formatBytes, getJson } from '../lib';
import { type MetricSeriesDto } from '../types';

interface Props {
    server: { id: string; name: string };
    ranges: string[];
    links: { logs: string; grafana: string | null };
}

type ChartKey = 'cpu' | 'memory' | 'disk' | 'load1' | 'load5' | 'load15' | 'network' | 'disk_io';

interface MetricsResponse {
    charts: Partial<Record<ChartKey, MetricSeriesDto[]>>;
    errors: Partial<Record<ChartKey, string>>;
}

const perSecond = (value: number) => `${formatBytes(value)}/s`;

export default function ServerMetrics({ server, ranges, links }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: 'Metrics', href: `/telemetry/servers/${server.id}/metrics` },
    ];

    const [range, setRange] = useState(ranges[0] ?? '1h');
    const [data, setData] = useState<MetricsResponse | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    const load = useCallback(
        async (next: string) => {
            setLoading(true);

            try {
                setData(await getJson<MetricsResponse>(`/telemetry/servers/${server.id}/metrics/data?range=${encodeURIComponent(next)}`));
                setError(null);
            } catch (e) {
                setError(e instanceof Error ? e.message : 'Failed to load metrics');
            } finally {
                setLoading(false);
            }
        },
        [server.id],
    );

    useEffect(() => {
        void load(range);
        const timer = window.setInterval(() => void load(range), 60_000);

        return () => window.clearInterval(timer);
    }, [load, range]);

    const charts = data?.charts ?? {};
    const errors = data?.errors ?? {};
    const longRange = range === '7d';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${server.name} · Metrics`} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title={`${server.name} metrics`} description="Host metrics from kiln-agent (OTel system.* conventions)" />
                    <div className="flex flex-wrap items-center gap-1">
                        {ranges.map((option) => (
                            <Button key={option} size="sm" variant={option === range ? 'secondary' : 'ghost'} onClick={() => setRange(option)}>
                                {option}
                            </Button>
                        ))}
                        <Button size="icon" variant="ghost" onClick={() => void load(range)} disabled={loading} aria-label="Refresh">
                            <RefreshCw className={loading ? 'animate-spin' : undefined} />
                        </Button>
                        <Button size="sm" variant="outline" asChild>
                            <Link href={links.logs}>
                                <ScrollText /> Logs
                            </Link>
                        </Button>
                        {links.grafana && (
                            <Button size="sm" variant="outline" asChild>
                                <a href={links.grafana} target="_blank" rel="noreferrer">
                                    <ExternalLink /> Grafana
                                </a>
                            </Button>
                        )}
                    </div>
                </div>
                {error && (
                    <Alert variant="destructive">
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}
                <div className="grid gap-4 lg:grid-cols-2">
                    <MetricChart
                        title="CPU & memory"
                        unit="%"
                        domain={[0, 100]}
                        longRange={longRange}
                        error={errors.cpu ?? errors.memory}
                        lines={[
                            { name: 'CPU', series: charts.cpu ?? [] },
                            { name: 'Memory', series: charts.memory ?? [] },
                        ]}
                    />
                    <MetricChart
                        title="Load average"
                        longRange={longRange}
                        error={errors.load1}
                        lines={[
                            { name: '1m', series: charts.load1 ?? [] },
                            { name: '5m', series: charts.load5 ?? [] },
                            { name: '15m', series: charts.load15 ?? [] },
                        ]}
                    />
                    <MetricChart
                        title="Disk usage"
                        unit="%"
                        domain={[0, 100]}
                        longRange={longRange}
                        error={errors.disk}
                        lines={[{ name: 'Disk', series: charts.disk ?? [], splitBy: 'system_filesystem_mountpoint' }]}
                    />
                    <MetricChart
                        title="Network"
                        formatValue={perSecond}
                        longRange={longRange}
                        error={errors.network}
                        lines={[{ name: 'Network', series: charts.network ?? [], splitBy: 'network_io_direction' }]}
                    />
                    <MetricChart
                        title="Disk I/O"
                        formatValue={perSecond}
                        longRange={longRange}
                        error={errors.disk_io}
                        lines={[{ name: 'Disk', series: charts.disk_io ?? [], splitBy: 'disk_io_direction' }]}
                    />
                </div>
            </div>
        </AppLayout>
    );
}
