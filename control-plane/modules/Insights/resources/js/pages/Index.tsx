import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Bug, HeartPulse, Lightbulb } from 'lucide-react';
import { ago, formatCount, formatMs } from '../components/insights-ui';

interface SiteRow {
    id: string;
    name: string;
    last_seen_at: string;
    requests_24h: number;
    errors_24h: number;
    p95_ms_24h: number | null;
    exceptions_24h: number;
    open_issues: number;
}

interface Props {
    sites: SiteRow[];
    openIssues: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Insights', href: '/insights' }];

export default function Index({ sites, openIssues }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Insights" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Insights" description="Requests, exceptions, jobs and scheduled tasks reported by your applications" />
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href={route('insights.heartbeats.index')}>
                                <HeartPulse /> Heartbeats
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href={route('insights.issues.index')}>
                                <Bug /> {openIssues} open issue{openIssues === 1 ? '' : 's'}
                            </Link>
                        </Button>
                    </div>
                </div>

                {sites.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-2 py-12 text-center">
                            <Lightbulb className="text-muted-foreground size-8" aria-hidden />
                            <p className="font-medium">No application data yet</p>
                            <p className="text-muted-foreground max-w-md text-sm">
                                Install <code>kiln/apm-laravel</code> or <code>@kiln/apm-node</code> in a site. The Kiln agent forwards exceptions and
                                per-minute summaries here automatically.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <Card className="py-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Site</TableHead>
                                    <TableHead className="text-right">Requests (24h)</TableHead>
                                    <TableHead className="text-right">Errors</TableHead>
                                    <TableHead className="text-right">p95</TableHead>
                                    <TableHead className="text-right">Exceptions</TableHead>
                                    <TableHead className="text-right">Open issues</TableHead>
                                    <TableHead>Last data</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sites.map((site) => (
                                    <TableRow key={site.id}>
                                        <TableCell className="font-medium">
                                            <Link href={route('insights.sites.show', site.id)} className="hover:underline">
                                                {site.name}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{formatCount(site.requests_24h)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{formatCount(site.errors_24h)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{formatMs(site.p95_ms_24h)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{formatCount(site.exceptions_24h)}</TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {site.open_issues > 0 ? (
                                                <Link
                                                    href={route('insights.issues.index', { site: site.id })}
                                                    className="font-medium text-red-600 hover:underline dark:text-red-400"
                                                >
                                                    {site.open_issues}
                                                </Link>
                                            ) : (
                                                0
                                            )}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground text-sm">{ago(site.last_seen_at)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
