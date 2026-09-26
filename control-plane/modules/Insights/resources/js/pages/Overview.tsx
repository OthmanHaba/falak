import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { format } from 'date-fns';
import { Activity, ExternalLink, ScrollText, Settings2 } from 'lucide-react';
import { type ReactNode } from 'react';
import { Area, AreaChart, Bar, BarChart, CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { ago, formatCount, formatMs, IssueKindBadge, SERIES, StatTile } from '../components/insights-ui';
import { type IssueKind, type IssuePriority, type OverviewPoint, type TopEntry } from '../types';

interface Props {
    site: { id: string; name: string };
    overview: {
        range: string;
        bucket_minutes: number;
        totals: {
            requests: number;
            request_errors: number;
            request_p95_ms: number | null;
            jobs: number;
            failed_jobs: number;
            queries: number;
            outgoing_requests: number;
            exceptions_handled: number;
            exceptions_unhandled: number;
            cache_hit_ratio: number | null;
            cache_hits: number;
            cache_misses: number;
        };
        series: OverviewPoint[];
        routes: TopEntry[];
        jobs: TopEntry[];
        queries: TopEntry[];
        outgoing: TopEntry[];
        issues: {
            id: string;
            kind: IssueKind;
            title: string;
            culprit: string | null;
            occurrences: number;
            affected_users: number;
            priority: IssuePriority;
            last_seen_at: string;
        }[];
    };
    ranges: string[];
    links: { logs: string; errorLogs: string; traces: string; slowTraces: string; grafana: string | null };
    can: { manage: boolean };
}

function ChartCard({ title, children }: { title: string; children: ReactNode }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm">{title}</CardTitle>
            </CardHeader>
            <CardContent>
                <div className="h-56 w-full">
                    <ResponsiveContainer width="100%" height="100%">
                        {children as React.ReactElement}
                    </ResponsiveContainer>
                </div>
            </CardContent>
        </Card>
    );
}

function TopTable({ title, rows, empty, emphasis }: { title: string; rows: TopEntry[]; empty: string; emphasis: 'p95' | 'max' | 'count' }) {
    return (
        <Card className="gap-2">
            <CardHeader>
                <CardTitle className="text-sm">{title}</CardTitle>
            </CardHeader>
            <CardContent className="px-0">
                {rows.length === 0 ? (
                    <p className="text-muted-foreground px-6 py-4 text-sm">{empty}</p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-6">Name</TableHead>
                                <TableHead className="text-right">Count</TableHead>
                                <TableHead className="text-right">Errors</TableHead>
                                <TableHead className={emphasis === 'p95' ? 'text-right font-semibold' : 'text-right'}>p95</TableHead>
                                <TableHead className={emphasis === 'max' ? 'pr-6 text-right font-semibold' : 'pr-6 text-right'}>Max</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row) => (
                                <TableRow key={row.name}>
                                    <TableCell className="max-w-[22rem] truncate pl-6 font-mono text-xs" title={row.name}>
                                        {row.name}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">{formatCount(row.count)}</TableCell>
                                    <TableCell className="text-right tabular-nums">{row.errors > 0 ? formatCount(row.errors) : '—'}</TableCell>
                                    <TableCell className="text-right tabular-nums">{formatMs(row.p95_ms)}</TableCell>
                                    <TableCell className="pr-6 text-right tabular-nums">{formatMs(row.max_ms)}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}

export default function Overview({ site, overview, ranges, links, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Insights', href: '/insights' },
        { title: site.name, href: route('insights.sites.show', site.id) },
    ];
    const { totals } = overview;
    const tick = (value: string) => format(new Date(value), overview.bucket_minutes >= 120 ? 'EEE HH:mm' : 'HH:mm');
    const label = (value: unknown) => format(new Date(String(value)), 'PPp');
    const errorRate = totals.requests > 0 ? (totals.request_errors / totals.requests) * 100 : 0;
    const axis = { fontSize: 11, tickLine: false, axisLine: false } as const;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${site.name} · Insights`} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title={site.name} description="Application overview" />
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="flex gap-1" role="group" aria-label="Time range">
                            {ranges.map((range) => (
                                <Button
                                    key={range}
                                    size="sm"
                                    variant={range === overview.range ? 'secondary' : 'ghost'}
                                    onClick={() =>
                                        router.get(route('insights.sites.show', site.id), { range }, { preserveScroll: true, replace: true })
                                    }
                                >
                                    {range}
                                </Button>
                            ))}
                        </div>
                        <Button size="sm" variant="outline" asChild>
                            <a href={links.logs}>
                                <ScrollText /> Logs
                            </a>
                        </Button>
                        <Button size="sm" variant="outline" asChild>
                            <a href={links.traces}>
                                <Activity /> Traces
                            </a>
                        </Button>
                        {links.grafana && (
                            <Button size="sm" variant="outline" asChild>
                                <a href={links.grafana} target="_blank" rel="noreferrer">
                                    <ExternalLink /> Grafana
                                </a>
                            </Button>
                        )}
                        <Button size="sm" variant="ghost" asChild>
                            <Link href={route('insights.sites.settings', site.id)} aria-label="Thresholds and heartbeats">
                                <Settings2 /> {can.manage ? 'Configure' : 'Settings'}
                            </Link>
                        </Button>
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                    <StatTile label="Requests" value={formatCount(totals.requests)} hint={`${errorRate.toFixed(2)}% errors`} />
                    <StatTile label="Request p95" value={formatMs(totals.request_p95_ms)} />
                    <StatTile
                        label="Unhandled exceptions"
                        value={formatCount(totals.exceptions_unhandled)}
                        hint={`${formatCount(totals.exceptions_handled)} handled`}
                        tone={totals.exceptions_unhandled > 0 ? 'danger' : undefined}
                    />
                    <StatTile
                        label="Jobs"
                        value={formatCount(totals.jobs)}
                        hint={`${formatCount(totals.failed_jobs)} failed`}
                        tone={totals.failed_jobs > 0 ? 'danger' : undefined}
                    />
                    <StatTile
                        label="Queries"
                        value={formatCount(totals.queries)}
                        hint={`${formatCount(totals.outgoing_requests)} outgoing requests`}
                    />
                    <StatTile
                        label="Cache hit ratio"
                        value={totals.cache_hit_ratio === null ? '—' : `${(totals.cache_hit_ratio * 100).toFixed(1)}%`}
                        hint={`${formatCount(totals.cache_hits)} hits · ${formatCount(totals.cache_misses)} misses`}
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <ChartCard title="Requests">
                        <AreaChart data={overview.series} margin={{ top: 5, right: 8, bottom: 0, left: -16 }}>
                            <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-border" />
                            <XAxis dataKey="t" tickFormatter={tick} minTickGap={40} {...axis} />
                            <YAxis allowDecimals={false} {...axis} />
                            <Tooltip labelFormatter={label} />
                            <Area
                                type="monotone"
                                dataKey="requests"
                                name="Requests"
                                stroke={SERIES.primary}
                                fill={SERIES.primary}
                                fillOpacity={0.15}
                                strokeWidth={2}
                            />
                        </AreaChart>
                    </ChartCard>
                    <ChartCard title="Exceptions">
                        <BarChart data={overview.series} margin={{ top: 5, right: 8, bottom: 0, left: -16 }}>
                            <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-border" />
                            <XAxis dataKey="t" tickFormatter={tick} minTickGap={40} {...axis} />
                            <YAxis allowDecimals={false} {...axis} />
                            <Tooltip labelFormatter={label} cursor={{ fillOpacity: 0.1 }} />
                            <Legend iconType="circle" wrapperStyle={{ fontSize: 12 }} />
                            <Bar dataKey="exceptions_unhandled" name="Unhandled" stackId="e" fill={SERIES.secondary} />
                            <Bar dataKey="exceptions_handled" name="Handled" stackId="e" fill={SERIES.primary} radius={[4, 4, 0, 0]} />
                        </BarChart>
                    </ChartCard>
                    <ChartCard title="Request p95">
                        <LineChart data={overview.series} margin={{ top: 5, right: 8, bottom: 0, left: -8 }}>
                            <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-border" />
                            <XAxis dataKey="t" tickFormatter={tick} minTickGap={40} {...axis} />
                            <YAxis tickFormatter={(v: number) => formatMs(v)} {...axis} />
                            <Tooltip labelFormatter={label} formatter={(v) => formatMs(Number(v))} />
                            <Line
                                type="monotone"
                                dataKey="request_p95_ms"
                                name="p95"
                                stroke={SERIES.primary}
                                strokeWidth={2}
                                dot={false}
                                connectNulls
                            />
                        </LineChart>
                    </ChartCard>
                </div>

                <Card className="gap-2">
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="text-sm">Open issues</CardTitle>
                        <Link href={route('insights.issues.index', { site: site.id })} className="text-muted-foreground text-xs hover:underline">
                            View all
                        </Link>
                    </CardHeader>
                    <CardContent>
                        {overview.issues.length === 0 ? (
                            <p className="text-muted-foreground text-sm">No open issues. 🎉</p>
                        ) : (
                            <ul className="divide-y">
                                {overview.issues.map((issue) => (
                                    <li key={issue.id} className="flex items-center justify-between gap-4 py-2">
                                        <div className="min-w-0">
                                            <Link
                                                href={route('insights.issues.show', issue.id)}
                                                className="block truncate font-medium hover:underline"
                                            >
                                                {issue.title}
                                            </Link>
                                            <p className="text-muted-foreground truncate text-xs">{issue.culprit}</p>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-3 text-xs">
                                            <IssueKindBadge kind={issue.kind} />
                                            <span className="tabular-nums">{formatCount(issue.occurrences)}×</span>
                                            <span className="text-muted-foreground">{ago(issue.last_seen_at)}</span>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                <div className="grid gap-4 xl:grid-cols-2">
                    <TopTable title="Slowest routes (p95)" rows={overview.routes} empty="No requests in this range." emphasis="p95" />
                    <TopTable title="Slow queries (max)" rows={overview.queries} empty="No queries in this range." emphasis="max" />
                    <TopTable title="Jobs" rows={overview.jobs} empty="No jobs in this range." emphasis="count" />
                    <TopTable title="Outgoing requests (p95)" rows={overview.outgoing} empty="No outgoing requests in this range." emphasis="p95" />
                </div>

                <p className="text-muted-foreground text-xs">
                    Need raw events? Open{' '}
                    <a className="underline" href={links.errorLogs}>
                        error logs
                    </a>{' '}
                    or{' '}
                    <a className="underline" href={links.slowTraces}>
                        traces slower than 1 s
                    </a>{' '}
                    for this site.
                </p>
            </div>
        </AppLayout>
    );
}
