import { Button } from '@/components/kiln/button';
import { DataTable } from '@/components/kiln/data-table';
import { Dialog } from '@/components/kiln/dialog';
import { Field } from '@/components/kiln/field';
import { Input } from '@/components/kiln/input';
import { RelativeTime } from '@/components/kiln/relative-time';
import { Section } from '@/components/kiln/section';
import { Select } from '@/components/kiln/select';
import { Switch } from '@/components/kiln/switch';
import { Tag } from '@/components/kiln/tag';
import ObservabilityLayout from '@/layouts/observability-layout';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Gauge, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState, type FormEventHandler } from 'react';
import { HeartbeatTable } from '../components/heartbeat-table';
import { type HeartbeatMonitor } from '../types';

interface Threshold {
    id: string;
    event_type: string;
    name_pattern: string | null;
    metric: string;
    threshold_ms: number;
    window_minutes: number;
    min_count: number;
    enabled: boolean;
    description: string;
    last_evaluated_at: string | null;
    last_breached_at: string | null;
}

type Option = { value: string; label: string };

interface Props {
    site: { id: string; name: string };
    thresholds: Threshold[];
    heartbeats: HeartbeatMonitor[];
    eventTypes: Option[];
    metrics: Option[];
    can: { manage: boolean };
}

function ThresholdDialog({
    siteId,
    eventTypes,
    metrics,
    threshold,
    onClose,
}: {
    siteId: string;
    eventTypes: Option[];
    metrics: Option[];
    threshold?: Threshold;
    onClose: () => void;
}) {
    const form = useForm({
        event_type: threshold?.event_type ?? 'request',
        name_pattern: threshold?.name_pattern ?? '',
        metric: threshold?.metric ?? 'p95',
        threshold_ms: String(threshold?.threshold_ms ?? 1000),
        window_minutes: String(threshold?.window_minutes ?? 5),
        min_count: String(threshold?.min_count ?? 10),
        enabled: threshold?.enabled ?? true,
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        if (threshold) form.put(route('insights.thresholds.update', threshold.id), options);
        else form.post(route('insights.thresholds.store', siteId), options);
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={threshold ? 'Edit threshold' : 'New threshold'}
            description="Opens a performance issue (one per name) when the metric stays above the limit over the window."
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="threshold-form" loading={form.processing}>
                        {threshold ? 'Save' : 'Add threshold'}
                    </Button>
                </>
            }
        >
            <form id="threshold-form" onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                <Field label="Watch" error={form.errors.event_type}>
                    <Select value={form.data.event_type} onValueChange={(value) => form.setData('event_type', value)} options={eventTypes} />
                </Field>
                <Field label="Metric" error={form.errors.metric}>
                    <Select value={form.data.metric} onValueChange={(value) => form.setData('metric', value)} options={metrics} />
                </Field>
                <Field
                    label="Name pattern"
                    hint="Empty watches every name; * is a wildcard."
                    error={form.errors.name_pattern}
                    className="sm:col-span-2"
                >
                    <Input
                        value={form.data.name_pattern}
                        onChange={(event) => form.setData('name_pattern', event.target.value)}
                        placeholder="GET /api/*"
                        mono
                    />
                </Field>
                <Field label="Above" error={form.errors.threshold_ms}>
                    <Input
                        type="number"
                        min={1}
                        value={form.data.threshold_ms}
                        onChange={(event) => form.setData('threshold_ms', event.target.value)}
                        suffix={<span className="text-xs">ms</span>}
                    />
                </Field>
                <Field label="Over window" error={form.errors.window_minutes}>
                    <Input
                        type="number"
                        min={1}
                        max={1440}
                        value={form.data.window_minutes}
                        onChange={(event) => form.setData('window_minutes', event.target.value)}
                        suffix={<span className="text-xs">min</span>}
                    />
                </Field>
                <Field label="Minimum samples" error={form.errors.min_count}>
                    <Input type="number" min={1} value={form.data.min_count} onChange={(event) => form.setData('min_count', event.target.value)} />
                </Field>
                <Field label="Enabled" inline className="self-end">
                    <Switch checked={form.data.enabled} onCheckedChange={(checked) => form.setData('enabled', checked)} />
                </Field>
            </form>
        </Dialog>
    );
}

/** Per-site performance thresholds and heartbeat monitors (reached from the site's Observability tab). */
export default function Settings({ site, thresholds, heartbeats, eventTypes, metrics, can }: Props) {
    const [editing, setEditing] = useState<Threshold | 'new' | null>(null);
    const label = (type: string) => eventTypes.find((option) => option.value === type)?.label ?? type;

    return (
        <ObservabilityLayout
            tab="overview"
            title={`${site.name} · Thresholds`}
            breadcrumbs={[
                { title: site.name, href: `/observability?site=${site.id}` },
                { title: 'Thresholds', href: route('insights.sites.settings', site.id) },
            ]}
            header={
                <div className="grid gap-1">
                    <Link
                        href={`/observability?site=${site.id}`}
                        className="text-fg-muted hover:text-fg inline-flex w-fit items-center gap-1 text-xs"
                    >
                        <ArrowLeft className="size-3.5" /> {site.name}
                    </Link>
                    <h1 className="text-fg text-lg font-semibold">Thresholds &amp; heartbeats</h1>
                    <p className="text-fg-muted text-sm">Performance issues and scheduled task monitoring for {site.name}.</p>
                </div>
            }
        >
            <Section
                title="Performance thresholds"
                description="When a route, job, query, command, scheduled task or outgoing request exceeds its threshold over the window, Kiln opens a performance issue and alerts through your alert rules."
                aside={
                    can.manage && (
                        <Button size="sm" variant="primary" icon={<Plus />} onClick={() => setEditing('new')}>
                            Add threshold
                        </Button>
                    )
                }
                bare
            >
                <DataTable
                    label="Performance thresholds"
                    rows={thresholds}
                    rowKey={(threshold) => threshold.id}
                    columns={[
                        {
                            id: 'watch',
                            header: 'Watch',
                            cell: (threshold) => (
                                <span className={threshold.enabled ? 'text-fg' : 'text-fg-muted'}>{label(threshold.event_type)}</span>
                            ),
                        },
                        {
                            id: 'name',
                            header: 'Name',
                            cell: (threshold) => <span className="font-mono text-xs">{threshold.name_pattern ?? 'all'}</span>,
                        },
                        {
                            id: 'condition',
                            header: 'Condition',
                            cell: (threshold) => (
                                <span className="flex flex-wrap items-center gap-1.5 text-sm">
                                    {threshold.description}
                                    {threshold.min_count > 1 && <span className="text-fg-faint text-xs">≥ {threshold.min_count} samples</span>}
                                    {!threshold.enabled && <Tag tone="faint">paused</Tag>}
                                </span>
                            ),
                        },
                        {
                            id: 'breach',
                            header: 'Last breach',
                            align: 'right',
                            cell: (threshold) => (
                                <RelativeTime value={threshold.last_breached_at} fallback="never" className="text-fg-muted text-xs" />
                            ),
                        },
                    ]}
                    rowActions={
                        can.manage
                            ? (threshold) => [
                                  { label: 'Edit', icon: <Pencil />, onSelect: () => setEditing(threshold) },
                                  { type: 'separator' },
                                  {
                                      label: 'Delete',
                                      icon: <Trash2 />,
                                      danger: true,
                                      onSelect: () => router.delete(route('insights.thresholds.destroy', threshold.id), { preserveScroll: true }),
                                  },
                              ]
                            : undefined
                    }
                    empty={{
                        icon: <Gauge />,
                        size: 'sm',
                        title: 'No thresholds',
                        description: 'Add one to open an issue when, say, GET /checkout p95 stays above 1s for 5 minutes.',
                    }}
                />
            </Section>
            <Section title="Scheduled task heartbeats" bare>
                <HeartbeatTable monitors={heartbeats} canManage={can.manage} defaultGrace={120} />
            </Section>
            {editing && (
                <ThresholdDialog
                    siteId={site.id}
                    eventTypes={eventTypes}
                    metrics={metrics}
                    threshold={editing === 'new' ? undefined : editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </ObservabilityLayout>
    );
}
