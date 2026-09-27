import { Button } from '@/components/kiln/button';
import { DataTable, type DataTableColumn } from '@/components/kiln/data-table';
import { EmptyState } from '@/components/kiln/empty-state';
import { Menu } from '@/components/kiln/menu';
import { MetricChart, type MetricPoint } from '@/components/kiln/metric-chart';
import { RelativeTime } from '@/components/kiln/relative-time';
import { Segmented } from '@/components/kiln/segmented';
import { Select } from '@/components/kiln/select';
import { Stat } from '@/components/kiln/stat';
import ObservabilityLayout from '@/layouts/observability-layout';
import { Link, router } from '@inertiajs/react';
import { Activity, ExternalLink, Gauge, ScrollText, Timer } from 'lucide-react';
import { useMemo } from 'react';
import { formatCount, formatMs, formatMsShort, formatPercent } from '../components/insights-ui';
import { Block, IssueList, TopList, timeFormatFor } from '../components/overview-parts';
import { type OverviewData } from '../types';

interface SiteRow {
    id: string;
    name: string;
    last_seen_at: string;
    requests: number;
    errors: number;
    p95_ms: number | null;
    exceptions: number;
    open_issues: number;
}

interface Props {
    filters: { range: string; project: string | null; site: string | null };
    ranges: string[];
    projects: { id: string; name: string }[];
    sites: { id: string; name: string }[];
    overview: OverviewData;
    siteRows: SiteRow[];
    issueSites: Record<string, string>;
    openIssues: number;
    heartbeats: { total: number; healthy: number; missed: number; failing: number };
    links: { logs: string; errorLogs: string; traces: string; slowTraces: string; grafana: string | null };
    can: { manage: boolean };
}

const ALL = '__all__';

export default function Overview({ filters, ranges, projects, sites, overview, siteRows, issueSites, openIssues, heartbeats, links }: Props) {
    const { totals } = overview;
    const visit = (patch: Partial<Props['filters']>) => {
        const next = { ...filters, ...patch };
        router.get(
            '/observability',
            Object.fromEntries(Object.entries(next).filter(([key, value]) => value && !(key === 'range' && value === '24h'))),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const timeFormat = useMemo(() => timeFormatFor(filters.range), [filters.range]);
    const errorRate = totals.requests > 0 ? totals.request_errors / totals.requests : null;
    const exceptions = totals.exceptions_handled + totals.exceptions_unhandled;
    const hasData = totals.requests + totals.jobs + exceptions + totals.queries > 0 || sites.length > 0;
    const siteName = filters.site ? (sites.find((site) => site.id === filters.site)?.name ?? filters.site) : null;
    const series = overview.series as unknown as MetricPoint[];

    const siteColumns: DataTableColumn<SiteRow>[] = [
        { id: 'name', header: 'Site', cell: (row) => <span className="text-fg font-medium">{row.name}</span>, sortValue: (row) => row.name },
        { id: 'requests', header: 'Requests', align: 'right', cell: (row) => formatCount(row.requests), sortValue: (row) => row.requests },
        {
            id: 'errors',
            header: 'Error rate',
            align: 'right',
            cell: (row) => (
                <span className={row.requests > 0 && row.errors / row.requests >= 0.01 ? 'text-danger' : 'text-fg'}>
                    {row.requests > 0 ? formatPercent(row.errors / row.requests) : '—'}
                </span>
            ),
            sortValue: (row) => (row.requests > 0 ? row.errors / row.requests : null),
        },
        { id: 'p95', header: 'p95', align: 'right', cell: (row) => formatMs(row.p95_ms), sortValue: (row) => row.p95_ms, hideOnMobile: true },
        {
            id: 'exceptions',
            header: 'Exceptions',
            align: 'right',
            cell: (row) => <span className={row.exceptions > 0 ? 'text-fg' : 'text-fg-muted'}>{formatCount(row.exceptions)}</span>,
            sortValue: (row) => row.exceptions,
        },
        {
            id: 'issues',
            header: 'Open issues',
            align: 'right',
            cell: (row) =>
                row.open_issues > 0 ? (
                    <Link
                        href={`/observability/issues?site=${row.id}`}
                        className="text-fg hover:text-primary underline-offset-2 hover:underline"
                        onClick={(event) => event.stopPropagation()}
                    >
                        {row.open_issues}
                    </Link>
                ) : (
                    <span className="text-fg-faint">0</span>
                ),
            sortValue: (row) => row.open_issues,
            hideOnMobile: true,
        },
        {
            id: 'seen',
            header: 'Last event',
            align: 'right',
            cell: (row) => <RelativeTime value={row.last_seen_at} className="text-fg-muted text-xs" />,
            sortValue: (row) => row.last_seen_at,
            hideOnMobile: true,
        },
    ];

    return (
        <ObservabilityLayout
            tab="overview"
            actions={
                <>
                    {projects.length > 1 && (
                        <Select
                            className="w-40"
                            aria-label="Project"
                            value={filters.project ?? ALL}
                            onValueChange={(value) => visit({ project: value === ALL ? null : value, site: null })}
                            options={[
                                { value: ALL, label: 'All projects' },
                                ...projects.map((project) => ({ value: project.id, label: project.name })),
                            ]}
                        />
                    )}
                    {sites.length > 0 && (
                        <Select
                            className="w-40"
                            aria-label="Site"
                            value={filters.site ?? ALL}
                            onValueChange={(value) => visit({ site: value === ALL ? null : value })}
                            options={[{ value: ALL, label: 'All sites' }, ...sites.map((site) => ({ value: site.id, label: site.name }))]}
                        />
                    )}
                    <Segmented
                        label="Time range"
                        value={filters.range}
                        onValueChange={(range) => visit({ range })}
                        options={ranges.map((range) => ({ value: range, label: range }))}
                    />
                </>
            }
        >
            {!hasData ? (
                <EmptyState
                    icon={<Gauge />}
                    title="No application data yet"
                    description="Requests, jobs, queries and exceptions appear here once a Laravel site reports insights through the Kiln agent. Deploy a site with the Kiln Insights package installed to start collecting."
                    action={
                        <Button asChild variant="primary">
                            <Link href="/projects">Go to projects</Link>
                        </Button>
                    }
                />
            ) : (
                <>
                    <section aria-label="Totals" className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                        <Stat label="Requests" value={formatCount(totals.requests)} hint={siteName ? siteName : `last ${filters.range}`} />
                        <Stat
                            label="Error rate"
                            value={formatPercent(errorRate)}
                            tone={errorRate !== null && errorRate >= 0.01 ? 'danger' : undefined}
                            hint={`${formatCount(totals.request_errors)} failed requests`}
                        />
                        <Stat label="p95 latency" value={formatMs(totals.request_p95_ms)} hint="weighted across routes" />
                        <Stat
                            label="Exceptions"
                            value={formatCount(exceptions)}
                            tone={totals.exceptions_unhandled > 0 ? 'danger' : undefined}
                            hint={`${formatCount(totals.exceptions_unhandled)} unhandled · ${formatCount(totals.exceptions_handled)} handled`}
                        />
                        <Stat
                            label="Jobs"
                            value={formatCount(totals.jobs)}
                            tone={totals.jobs > 0 && totals.failed_jobs / totals.jobs >= 0.01 ? 'danger' : undefined}
                            hint={`${formatCount(totals.failed_jobs)} failed`}
                        />
                        <Stat
                            label="Open issues"
                            value={formatCount(openIssues)}
                            href={`/observability/issues${filters.site ? `?site=${filters.site}` : ''}`}
                            hint={
                                heartbeats.total > 0
                                    ? heartbeats.missed + heartbeats.failing > 0
                                        ? `${heartbeats.missed + heartbeats.failing} heartbeat${heartbeats.missed + heartbeats.failing === 1 ? '' : 's'} unhealthy`
                                        : `${heartbeats.total} heartbeats healthy`
                                    : 'across all kinds'
                            }
                        />
                    </section>

                    <section aria-label="Charts" className="grid gap-3 lg:grid-cols-2">
                        <MetricChart
                            title="Requests"
                            type="area"
                            data={series}
                            series={[
                                { key: 'requests', label: 'Requests' },
                                { key: 'request_errors', label: 'Failed' },
                            ]}
                            format={(value) => formatCount(Math.round(value))}
                            timeFormat={timeFormat}
                        />
                        <MetricChart
                            title="p95 latency"
                            data={series}
                            series={[{ key: 'request_p95_ms', label: 'p95' }]}
                            format={formatMsShort}
                            timeFormat={timeFormat}
                        />
                        <MetricChart
                            title="Exceptions"
                            type="bar"
                            data={series}
                            series={[
                                { key: 'exceptions_unhandled', label: 'Unhandled' },
                                { key: 'exceptions_handled', label: 'Handled' },
                            ]}
                            format={(value) => formatCount(Math.round(value))}
                            timeFormat={timeFormat}
                        />
                        <MetricChart
                            title="Jobs"
                            type="bar"
                            data={series}
                            series={[
                                { key: 'jobs', label: 'Processed' },
                                { key: 'failed_jobs', label: 'Failed' },
                            ]}
                            format={(value) => formatCount(Math.round(value))}
                            timeFormat={timeFormat}
                        />
                    </section>

                    <section className="grid gap-3 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                        <Block
                            title="Top issues"
                            aside={
                                <Link
                                    href={`/observability/issues${filters.site ? `?site=${filters.site}` : ''}`}
                                    className="text-fg-muted hover:text-fg"
                                >
                                    View all
                                </Link>
                            }
                        >
                            <IssueList issues={overview.issues} siteNames={issueSites} />
                        </Block>
                        <Block
                            title="Slow routes"
                            aside={
                                <Menu
                                    label="Explore"
                                    actions={[
                                        { label: 'Slow traces', icon: <Timer />, href: links.slowTraces },
                                        { label: 'All traces', icon: <Activity />, href: links.traces },
                                        { label: 'Error logs', icon: <ScrollText />, href: links.errorLogs },
                                        ...(links.grafana
                                            ? [
                                                  {
                                                      label: 'Grafana dashboard',
                                                      icon: <ExternalLink />,
                                                      onSelect: () => window.open(links.grafana!, '_blank'),
                                                  },
                                              ]
                                            : []),
                                    ]}
                                />
                            }
                        >
                            <TopList rows={overview.routes.slice(0, 6)} emphasis="p95" empty="No requests in this window." />
                        </Block>
                    </section>

                    <section className="grid gap-3 lg:grid-cols-2">
                        <Block title="Slow queries">
                            <TopList rows={overview.queries.slice(0, 6)} emphasis="max" empty="No queries recorded in this window." />
                        </Block>
                        <Block title="Jobs">
                            <TopList rows={overview.jobs.slice(0, 6)} emphasis="count" empty="No queued jobs ran in this window." />
                        </Block>
                    </section>

                    {siteRows.length > 0 && (
                        <section className="grid gap-3" aria-labelledby="sites-heading">
                            <h2 id="sites-heading" className="text-fg text-sm font-medium">
                                Sites
                            </h2>
                            <DataTable
                                label="Sites"
                                columns={siteColumns}
                                rows={siteRows}
                                rowKey={(row) => row.id}
                                onRowClick={(row) => visit({ site: row.id })}
                                defaultSort={{ column: 'requests', direction: 'desc' }}
                            />
                        </section>
                    )}
                </>
            )}
        </ObservabilityLayout>
    );
}
