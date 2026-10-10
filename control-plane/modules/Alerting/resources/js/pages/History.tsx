import { Button } from '@/components/falak/button';
import { EmptyState } from '@/components/falak/empty-state';
import { Pagination } from '@/components/falak/pagination';
import { RelativeTime } from '@/components/falak/relative-time';
import { Select } from '@/components/falak/select';
import { StatusBadge } from '@/components/falak/status';
import { Tag } from '@/components/falak/tag';
import ObservabilityLayout from '@/layouts/observability-layout';
import { type Paginated } from '@/types';
import { Link, router } from '@inertiajs/react';
import { format } from 'date-fns';
import { BellRing, ChevronDown, ChevronRight, Settings2, SquareArrowOutUpRight, Wrench } from 'lucide-react';
import { useState } from 'react';
import { openUrl, SeverityIndicator } from '../components/severity';
import { type AlertRow, type DeliveryRow } from '../types';

interface Props {
    alerts: Paginated<AlertRow>;
    filters: { outcome?: string; type?: string };
    outcomes: string[];
}

const ALL = '__all__';

const OUTCOMES: Record<string, { label: string; status: string }> = {
    delivered: { label: 'Delivered', status: 'succeeded' },
    deduplicated: { label: 'Deduplicated', status: 'skipped' },
    quiet_hours: { label: 'Quiet hours', status: 'skipped' },
    rate_limited: { label: 'Rate limited', status: 'degraded' },
    no_route: { label: 'No matching rule', status: 'inactive' },
    recovery_skipped: { label: 'Recovery skipped', status: 'skipped' },
};

function deliverySummary(deliveries: DeliveryRow[]): string {
    if (deliveries.length === 0) return 'No deliveries';
    const sent = deliveries.filter((delivery) => delivery.status === 'sent').length;
    const failed = deliveries.filter((delivery) => delivery.status === 'failed').length;
    const parts = [`${sent}/${deliveries.length} sent`];
    if (failed > 0) parts.push(`${failed} failed`);

    return parts.join(' · ');
}

function AlertItem({ alert }: { alert: AlertRow }) {
    const [open, setOpen] = useState(false);
    const outcome = OUTCOMES[alert.outcome] ?? { label: alert.outcome, status: 'inactive' };
    const failed = alert.deliveries.some((delivery) => delivery.status === 'failed');

    return (
        <li>
            <button
                type="button"
                aria-expanded={open}
                onClick={() => setOpen((value) => !value)}
                className="hover:bg-surface-2 focus-visible:outline-primary flex w-full items-start gap-3 px-4 py-3 text-left transition-colors duration-150 focus-visible:outline-2 focus-visible:-outline-offset-2"
            >
                <span className="text-fg-faint mt-0.5 shrink-0">
                    {open ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                </span>
                <SeverityIndicator severity={alert.severity} className="mt-1.5" />
                <span className="grid min-w-0 flex-1 gap-0.5">
                    <span className="flex min-w-0 flex-wrap items-center gap-2">
                        <span className="text-fg truncate text-sm font-medium">{alert.title}</span>
                        {alert.recovery && <Tag tone="success">Recovered</Tag>}
                    </span>
                    <span className="text-fg-faint flex flex-wrap items-center gap-x-2 text-xs">
                        <span className="font-mono">{alert.type}</span>
                        <span aria-hidden>·</span>
                        <span className={failed ? 'text-danger' : undefined}>{deliverySummary(alert.deliveries)}</span>
                    </span>
                </span>
                <span className="flex shrink-0 flex-col items-end gap-1">
                    <StatusBadge status={outcome.status} label={outcome.label} />
                    <RelativeTime value={alert.created_at} className="text-fg-faint text-xs" />
                </span>
            </button>
            {open && (
                <div className="bg-canvas border-border grid gap-3 border-t px-4 py-3 pl-14">
                    {alert.body && <p className="text-fg-muted text-sm whitespace-pre-wrap">{alert.body}</p>}
                    {alert.detail && <p className="text-fg-muted font-mono text-xs whitespace-pre-wrap">{alert.detail}</p>}
                    <p className="text-fg-faint text-xs">Raised {format(new Date(alert.created_at), 'PP HH:mm:ss')}</p>
                    {alert.deliveries.length > 0 && (
                        <ul className="border-border divide-border divide-y rounded-md border">
                            {alert.deliveries.map((delivery) => (
                                <li key={delivery.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-xs">
                                    <span className="text-fg font-medium">{delivery.channel?.name ?? 'Deleted channel'}</span>
                                    {delivery.channel && <Tag>{delivery.channel.type}</Tag>}
                                    <StatusBadge
                                        status={delivery.status === 'sent' ? 'succeeded' : delivery.status === 'failed' ? 'failed' : 'queued'}
                                        label={delivery.status}
                                    />
                                    {delivery.attempts > 1 && <span className="text-fg-faint">{delivery.attempts} attempts</span>}
                                    {delivery.sent_at && <RelativeTime value={delivery.sent_at} className="text-fg-faint" />}
                                    {delivery.error && <span className="text-danger basis-full font-mono">{delivery.error}</span>}
                                </li>
                            ))}
                        </ul>
                    )}
                    {alert.url && (
                        <div className="flex flex-wrap items-center gap-2">
                            {alert.action && <span className="text-fg-muted text-xs">Suggested fix:</span>}
                            <Button
                                size="sm"
                                variant={alert.action ? 'primary' : undefined}
                                icon={alert.action ? <Wrench /> : <SquareArrowOutUpRight />}
                                onClick={() => openUrl(alert.url!, (path) => router.visit(path))}
                            >
                                {alert.action ?? 'Open'}
                            </Button>
                        </div>
                    )}
                </div>
            )}
        </li>
    );
}

export default function History({ alerts, filters, outcomes }: Props) {
    const apply = (outcome: string) =>
        router.get('/observability/alerts', outcome === ALL ? {} : { outcome }, { preserveState: true, replace: true });

    return (
        <ObservabilityLayout
            tab="alerts"
            actions={
                <Button asChild variant="ghost" size="sm">
                    <Link href="/settings/alert-rules">
                        <Settings2 /> Channels &amp; rules
                    </Link>
                </Button>
            }
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-fg-muted text-sm">Every alert raised in this organization and where it was delivered.</p>
                <Select
                    size="sm"
                    className="w-44"
                    aria-label="Outcome"
                    value={filters.outcome ?? ALL}
                    onValueChange={apply}
                    options={[
                        { value: ALL, label: 'All outcomes' },
                        ...outcomes.map((outcome) => ({ value: outcome, label: OUTCOMES[outcome]?.label ?? outcome })),
                    ]}
                />
            </div>
            {alerts.data.length === 0 ? (
                <EmptyState
                    icon={<BellRing />}
                    title={filters.outcome ? 'No alerts with this outcome' : 'No alerts yet'}
                    description="Failed deploys, new issues, missed heartbeats and offline servers raise alerts. Route them to Slack, Discord, email or webhooks with alert rules."
                    action={
                        <Button asChild variant="primary" size="sm">
                            <Link href="/settings/alert-rules">Set up alert rules</Link>
                        </Button>
                    }
                />
            ) : (
                <ul className="border-border bg-surface-1 divide-border divide-y overflow-hidden rounded-lg border">
                    {alerts.data.map((alert) => (
                        <AlertItem key={alert.id} alert={alert} />
                    ))}
                </ul>
            )}
            <Pagination page={alerts} noun="alerts" />
        </ObservabilityLayout>
    );
}
