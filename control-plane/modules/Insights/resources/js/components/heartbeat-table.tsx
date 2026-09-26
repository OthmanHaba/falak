import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { router, useForm } from '@inertiajs/react';
import { CheckCircle2, Pencil, Trash2, XCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { type HeartbeatMonitor } from '../types';
import { ago } from './insights-ui';

function EditRow({ monitor, onDone, defaultGrace }: { monitor: HeartbeatMonitor; onDone: () => void; defaultGrace: number }) {
    const form = useForm({
        schedule: monitor.schedule ?? '',
        timezone: monitor.timezone,
        grace_seconds: monitor.grace_seconds === null ? '' : String(monitor.grace_seconds),
        enabled: monitor.enabled,
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            schedule: data.schedule || null,
            grace_seconds: data.grace_seconds === '' ? null : Number(data.grace_seconds),
        }));
        form.put(route('insights.heartbeats.update', monitor.id), { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <TableRow>
            <TableCell colSpan={6}>
                <form onSubmit={submit} className="grid gap-2 sm:grid-cols-[1fr_1fr_8rem_auto_auto] sm:items-end">
                    <label className="space-y-1 text-xs">
                        Schedule
                        <Input value={form.data.schedule} onChange={(e) => form.setData('schedule', e.target.value)} placeholder="*/5 * * * *" />
                        {form.errors.schedule && <span className="text-red-600">{form.errors.schedule}</span>}
                    </label>
                    <label className="space-y-1 text-xs">
                        Timezone
                        <Input value={form.data.timezone} onChange={(e) => form.setData('timezone', e.target.value)} />
                        {form.errors.timezone && <span className="text-red-600">{form.errors.timezone}</span>}
                    </label>
                    <label className="space-y-1 text-xs">
                        Grace (s)
                        <Input
                            type="number"
                            min={0}
                            value={form.data.grace_seconds}
                            placeholder={String(defaultGrace)}
                            onChange={(e) => form.setData('grace_seconds', e.target.value)}
                        />
                    </label>
                    <label className="flex items-center gap-2 pb-2 text-xs">
                        <Checkbox checked={form.data.enabled} onCheckedChange={(checked) => form.setData('enabled', checked === true)} /> Monitor
                    </label>
                    <div className="flex gap-2">
                        <Button type="submit" size="sm" disabled={form.processing}>
                            Save
                        </Button>
                        <Button type="button" size="sm" variant="ghost" onClick={onDone}>
                            Cancel
                        </Button>
                    </div>
                </form>
            </TableCell>
        </TableRow>
    );
}

export function HeartbeatTable({
    monitors,
    canManage,
    defaultGrace,
    showSite = false,
}: {
    monitors: HeartbeatMonitor[];
    canManage: boolean;
    defaultGrace: number;
    showSite?: boolean;
}) {
    const [editing, setEditing] = useState<string | null>(null);

    if (monitors.length === 0) {
        return (
            <p className="text-muted-foreground p-6 text-sm">
                No scheduled task has reported a heartbeat yet. Tasks appear here after their first run.
            </p>
        );
    }

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead className="pl-6">Task</TableHead>
                    {showSite && <TableHead>Site</TableHead>}
                    <TableHead>Schedule</TableHead>
                    <TableHead>Last run</TableHead>
                    <TableHead>Next expected</TableHead>
                    <TableHead className="pr-6 text-right" />
                </TableRow>
            </TableHeader>
            <TableBody>
                {monitors.map((monitor) =>
                    editing === monitor.id ? (
                        <EditRow key={monitor.id} monitor={monitor} defaultGrace={defaultGrace} onDone={() => setEditing(null)} />
                    ) : (
                        <TableRow key={monitor.id} className={monitor.enabled ? '' : 'opacity-60'}>
                            <TableCell className="pl-6">
                                <span className="flex items-center gap-2 font-mono text-sm">
                                    {monitor.healthy ? (
                                        <CheckCircle2 className="size-4 text-emerald-600" aria-label="Healthy" />
                                    ) : (
                                        <XCircle className="size-4 text-red-600" aria-label={monitor.missed_at ? 'Missed' : 'Failing'} />
                                    )}
                                    {monitor.job}
                                </span>
                                {!monitor.enabled && <span className="text-muted-foreground text-xs">not monitored</span>}
                                {monitor.missed_at && (
                                    <span className="text-xs text-red-600 dark:text-red-400">missed since {ago(monitor.missed_at)}</span>
                                )}
                            </TableCell>
                            {showSite && <TableCell className="text-sm">{monitor.site_name ?? '—'}</TableCell>}
                            <TableCell className="font-mono text-xs">
                                {monitor.schedule ?? '—'} <span className="text-muted-foreground">{monitor.timezone}</span>
                            </TableCell>
                            <TableCell className="text-sm">
                                {ago(monitor.last_run_at)}{' '}
                                {monitor.last_status && <span className="text-muted-foreground text-xs">({monitor.last_status})</span>}
                            </TableCell>
                            <TableCell className="text-sm">
                                {monitor.next_expected_at ? new Date(monitor.next_expected_at).toLocaleString() : '—'}
                            </TableCell>
                            <TableCell className="pr-6 text-right whitespace-nowrap">
                                {canManage && (
                                    <>
                                        <Button size="icon" variant="ghost" aria-label={`Edit ${monitor.job}`} onClick={() => setEditing(monitor.id)}>
                                            <Pencil />
                                        </Button>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            aria-label={`Delete ${monitor.job}`}
                                            onClick={() => {
                                                if (confirm(`Stop tracking ${monitor.job}? It reappears on its next heartbeat.`)) {
                                                    router.delete(route('insights.heartbeats.destroy', monitor.id), { preserveScroll: true });
                                                }
                                            }}
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
    );
}
