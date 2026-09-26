import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { format } from 'date-fns';
import { AlertingTabs, SeverityBadge } from '../components/alerting-ui';
import { type AlertRow, type DeliveryRow } from '../types';

interface Props {
    alerts: Paginated<AlertRow>;
    filters: { outcome?: string; type?: string };
    outcomes: string[];
}

const ALL = 'all';

const OUTCOME_LABELS: Record<string, string> = {
    delivered: 'Delivered',
    deduplicated: 'Deduplicated',
    quiet_hours: 'Quiet hours',
    rate_limited: 'Rate limited',
    no_route: 'No matching rule',
    recovery_skipped: 'Recovery skipped',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Alerts', href: '/alerting/rules' },
    { title: 'History', href: '/alerting/history' },
];

function DeliveryStatus({ delivery }: { delivery: DeliveryRow }) {
    const tone = delivery.status === 'sent' ? 'text-emerald-600' : delivery.status === 'failed' ? 'text-destructive' : 'text-muted-foreground';

    return (
        <li className="text-xs">
            <span className="font-medium">{delivery.channel?.name ?? 'deleted channel'}</span>{' '}
            <span className={tone} title={delivery.error ?? undefined}>
                {delivery.status}
                {delivery.attempts > 1 && ` (${delivery.attempts} attempts)`}
                {delivery.error && `: ${delivery.error}`}
            </span>
        </li>
    );
}

export default function History({ alerts, filters, outcomes }: Props) {
    const apply = (outcome: string) => {
        router.get(route('alerting.history'), outcome === ALL ? {} : { outcome }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Alert history" />
            <div className="space-y-6 p-4">
                <Heading title="Alerts" description="Every alert raised in this organization and what happened to it" />
                <AlertingTabs active="/alerting/history" />

                <div className="flex items-center gap-2">
                    <Select value={filters.outcome ?? ALL} onValueChange={apply}>
                        <SelectTrigger className="w-52" aria-label="Filter by outcome">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All outcomes</SelectItem>
                            {outcomes.map((outcome) => (
                                <SelectItem key={outcome} value={outcome}>
                                    {OUTCOME_LABELS[outcome] ?? outcome}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <Card>
                    <CardContent className="p-0">
                        {alerts.data.length === 0 ? (
                            <p className="text-muted-foreground p-10 text-center text-sm">No alerts.</p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-40">When</TableHead>
                                        <TableHead>Alert</TableHead>
                                        <TableHead>Outcome</TableHead>
                                        <TableHead>Deliveries</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {alerts.data.map((alert) => (
                                        <TableRow key={alert.id}>
                                            <TableCell className="text-muted-foreground align-top text-xs whitespace-nowrap">
                                                {format(new Date(alert.created_at), 'PP HH:mm:ss')}
                                            </TableCell>
                                            <TableCell className="align-top">
                                                <div className="flex items-center gap-2">
                                                    <SeverityBadge severity={alert.severity} />
                                                    {alert.recovery && <Badge variant="outline">Recovery</Badge>}
                                                    {alert.url ? (
                                                        <a href={alert.url} className="font-medium hover:underline">
                                                            {alert.title}
                                                        </a>
                                                    ) : (
                                                        <span className="font-medium">{alert.title}</span>
                                                    )}
                                                </div>
                                                {alert.body && <p className="text-muted-foreground mt-1 max-w-xl text-xs">{alert.body}</p>}
                                                <p className="text-muted-foreground mt-1 font-mono text-[11px]">{alert.type}</p>
                                            </TableCell>
                                            <TableCell className="align-top text-sm">{OUTCOME_LABELS[alert.outcome] ?? alert.outcome}</TableCell>
                                            <TableCell className="align-top">
                                                {alert.deliveries.length === 0 ? (
                                                    <span className="text-muted-foreground text-xs">—</span>
                                                ) : (
                                                    <ul className="space-y-0.5">
                                                        {alert.deliveries.map((delivery) => (
                                                            <DeliveryStatus key={delivery.id} delivery={delivery} />
                                                        ))}
                                                    </ul>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                {alerts.last_page > 1 && (
                    <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">
                            {alerts.from}–{alerts.to} of {alerts.total}
                        </span>
                        <div className="flex gap-1">
                            {alerts.links.map((link, index) => (
                                <Button
                                    key={index}
                                    asChild={link.url !== null}
                                    size="sm"
                                    variant={link.active ? 'secondary' : 'ghost'}
                                    disabled={link.url === null}
                                >
                                    {link.url ? (
                                        <Link href={link.url} preserveScroll dangerouslySetInnerHTML={{ __html: link.label }} />
                                    ) : (
                                        <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                    )}
                                </Button>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
