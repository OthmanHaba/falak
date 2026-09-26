import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { HeartbeatTable } from '../components/heartbeat-table';
import { ago } from '../components/insights-ui';
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

function ThresholdForm({
    siteId,
    eventTypes,
    metrics,
    threshold,
    onDone,
}: {
    siteId: string;
    eventTypes: Option[];
    metrics: Option[];
    threshold?: Threshold;
    onDone: () => void;
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
        const options = { preserveScroll: true, onSuccess: onDone };

        if (threshold) {
            form.put(route('insights.thresholds.update', threshold.id), options);
        } else {
            form.post(route('insights.thresholds.store', siteId), options);
        }
    };

    return (
        <form onSubmit={submit} className="grid gap-3 rounded-md border p-4 md:grid-cols-3">
            <div className="space-y-1">
                <Label>Watch</Label>
                <Select value={form.data.event_type} onValueChange={(v) => form.setData('event_type', v)}>
                    <SelectTrigger aria-label="Event type">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {eventTypes.map((o) => (
                            <SelectItem key={o.value} value={o.value}>
                                {o.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={form.errors.event_type} />
            </div>
            <div className="space-y-1 md:col-span-2">
                <Label htmlFor="name_pattern">Name pattern</Label>
                <Input
                    id="name_pattern"
                    value={form.data.name_pattern}
                    onChange={(e) => form.setData('name_pattern', e.target.value)}
                    placeholder="All — or e.g. GET /api/*"
                />
                <InputError message={form.errors.name_pattern} />
            </div>
            <div className="space-y-1">
                <Label>Metric</Label>
                <Select value={form.data.metric} onValueChange={(v) => form.setData('metric', v)}>
                    <SelectTrigger aria-label="Metric">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {metrics.map((o) => (
                            <SelectItem key={o.value} value={o.value}>
                                {o.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            <div className="space-y-1">
                <Label htmlFor="threshold_ms">Above (ms)</Label>
                <Input
                    id="threshold_ms"
                    type="number"
                    min={1}
                    value={form.data.threshold_ms}
                    onChange={(e) => form.setData('threshold_ms', e.target.value)}
                />
                <InputError message={form.errors.threshold_ms} />
            </div>
            <div className="space-y-1">
                <Label htmlFor="window_minutes">Over window (minutes)</Label>
                <Input
                    id="window_minutes"
                    type="number"
                    min={1}
                    max={1440}
                    value={form.data.window_minutes}
                    onChange={(e) => form.setData('window_minutes', e.target.value)}
                />
                <InputError message={form.errors.window_minutes} />
            </div>
            <div className="space-y-1">
                <Label htmlFor="min_count">Minimum samples</Label>
                <Input id="min_count" type="number" min={1} value={form.data.min_count} onChange={(e) => form.setData('min_count', e.target.value)} />
                <InputError message={form.errors.min_count} />
            </div>
            <label className="flex items-center gap-2 self-end pb-2 text-sm">
                <Checkbox checked={form.data.enabled} onCheckedChange={(checked) => form.setData('enabled', checked === true)} /> Enabled
            </label>
            <div className="flex items-end gap-2">
                <Button type="submit" disabled={form.processing}>
                    {threshold ? 'Save' : 'Add threshold'}
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

export default function Settings({ site, thresholds, heartbeats, eventTypes, metrics, can }: Props) {
    const [editing, setEditing] = useState<string | 'new' | null>(null);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Insights', href: '/insights' },
        { title: site.name, href: route('insights.sites.show', site.id) },
        { title: 'Settings', href: route('insights.sites.settings', site.id) },
    ];
    const label = (type: string) => eventTypes.find((o) => o.value === type)?.label ?? type;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${site.name} · Insights settings`} />
            <div className="space-y-6 p-4">
                <Heading title="Thresholds & heartbeats" description={`Performance issues and scheduled task monitoring for ${site.name}`} />

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="text-sm">Performance thresholds</CardTitle>
                        {can.manage && editing === null && (
                            <Button size="sm" onClick={() => setEditing('new')}>
                                <Plus /> Add threshold
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <p className="text-muted-foreground text-sm">
                            When a route, job, query, command, scheduled task or outgoing request exceeds its threshold over the window, Kiln opens a
                            performance issue (one per name) and alerts through your alert rules. p95 over a window is the sample-weighted mean of
                            per-minute p95s.
                        </p>
                        {editing === 'new' && (
                            <ThresholdForm siteId={site.id} eventTypes={eventTypes} metrics={metrics} onDone={() => setEditing(null)} />
                        )}
                        {thresholds.length === 0 && editing !== 'new' ? (
                            <p className="text-muted-foreground text-sm">No thresholds configured.</p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Watch</TableHead>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Condition</TableHead>
                                        <TableHead>Last breach</TableHead>
                                        <TableHead />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {thresholds.map((threshold) =>
                                        editing === threshold.id ? (
                                            <TableRow key={threshold.id}>
                                                <TableCell colSpan={5}>
                                                    <ThresholdForm
                                                        siteId={site.id}
                                                        eventTypes={eventTypes}
                                                        metrics={metrics}
                                                        threshold={threshold}
                                                        onDone={() => setEditing(null)}
                                                    />
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            <TableRow key={threshold.id} className={threshold.enabled ? '' : 'opacity-60'}>
                                                <TableCell>{label(threshold.event_type)}</TableCell>
                                                <TableCell className="font-mono text-xs">{threshold.name_pattern ?? 'all'}</TableCell>
                                                <TableCell className="text-sm">
                                                    {threshold.description}
                                                    {threshold.min_count > 1 && (
                                                        <span className="text-muted-foreground"> · ≥ {threshold.min_count} samples</span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground text-sm">
                                                    {threshold.last_breached_at ? ago(threshold.last_breached_at) : 'never'}
                                                </TableCell>
                                                <TableCell className="text-right whitespace-nowrap">
                                                    {can.manage && (
                                                        <>
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                aria-label="Edit threshold"
                                                                onClick={() => setEditing(threshold.id)}
                                                            >
                                                                <Pencil />
                                                            </Button>
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                aria-label="Delete threshold"
                                                                onClick={() =>
                                                                    router.delete(route('insights.thresholds.destroy', threshold.id), {
                                                                        preserveScroll: true,
                                                                    })
                                                                }
                                                            >
                                                                <Trash2 />
                                                            </Button>
                                                        </>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ),
                                    )}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                <Card className="gap-2 pb-0">
                    <CardHeader>
                        <CardTitle className="text-sm">Scheduled task heartbeats</CardTitle>
                    </CardHeader>
                    <HeartbeatTable monitors={heartbeats} canManage={can.manage} defaultGrace={120} />
                </Card>
            </div>
        </AppLayout>
    );
}
