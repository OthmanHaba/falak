import { Button } from '@/components/falak/button';
import { DataTable, type DataTableColumn } from '@/components/falak/data-table';
import { Dialog } from '@/components/falak/dialog';
import { Field } from '@/components/falak/field';
import { Input } from '@/components/falak/input';
import { RelativeTime } from '@/components/falak/relative-time';
import { StatusDot } from '@/components/falak/status';
import { Switch } from '@/components/falak/switch';
import { Tag } from '@/components/falak/tag';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import { format } from 'date-fns';
import { HeartPulse, Pencil, Trash2 } from 'lucide-react';
import { useState, type FormEventHandler } from 'react';
import { type HeartbeatMonitor, type HeartbeatRow, type HeartbeatSlot } from '../types';
import { formatMs } from './insights-ui';

type Row = HeartbeatMonitor & Partial<Pick<HeartbeatRow, 'expected_24h' | 'actual_24h' | 'missed_24h' | 'slots'>>;

export function monitorState(monitor: HeartbeatMonitor): { status: string; label: string } {
    if (!monitor.enabled) return { status: 'inactive', label: 'Not monitored' };
    if (monitor.missed_at) return { status: 'failed', label: 'Missed' };
    if (monitor.last_status === 'failed' || monitor.last_status === 'timeout')
        return { status: 'failed', label: monitor.last_status === 'timeout' ? 'Timed out' : 'Failing' };
    if (!monitor.last_run_at) return { status: 'queued', label: 'Waiting for first run' };

    return { status: 'healthy', label: 'Healthy' };
}

const SLOT_TONE: Record<string, string> = {
    finished: 'bg-success',
    failed: 'bg-danger',
    timeout: 'bg-danger',
    missed: 'bg-danger/40 outline outline-1 -outline-offset-1 outline-danger',
    skipped: 'bg-fg-faint',
};

/** Expected runs (last 24h) as a strip: one cell per expected slot, colored by what actually happened. */
export function HeartbeatStrip({ slots, className }: { slots: HeartbeatSlot[]; className?: string }) {
    if (slots.length === 0) return <span className="text-fg-faint text-xs">No runs expected yet</span>;

    return (
        <span
            className={cn('flex h-4 items-stretch gap-px', className)}
            role="img"
            aria-label={`${slots.filter((slot) => slot.status === 'missed').length} of ${slots.length} expected runs missed`}
        >
            {slots.map((slot) => (
                <span
                    key={slot.at}
                    title={`${format(new Date(slot.at), 'PP HH:mm')} · ${slot.status}${slot.duration_ms !== null ? ` · ${formatMs(slot.duration_ms)}` : ''}`}
                    className={cn('w-1.5 min-w-0.5 shrink rounded-[1px]', SLOT_TONE[slot.status] ?? 'bg-fg-faint')}
                />
            ))}
        </span>
    );
}

function EditDialog({ monitor, defaultGrace, onClose }: { monitor: HeartbeatMonitor; defaultGrace: number; onClose: () => void }) {
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
        form.put(route('insights.heartbeats.update', monitor.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={`Edit ${monitor.job}`}
            description="Falak expects a heartbeat for every scheduled run; a run that doesn't report within the grace period opens an issue."
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form={`heartbeat-${monitor.id}`} loading={form.processing}>
                        Save
                    </Button>
                </>
            }
        >
            <form id={`heartbeat-${monitor.id}`} onSubmit={submit} className="grid gap-4">
                <Field label="Schedule" hint="5-field cron, @hourly/@daily…, or @every 10m" error={form.errors.schedule}>
                    <Input
                        value={form.data.schedule}
                        onChange={(event) => form.setData('schedule', event.target.value)}
                        placeholder="*/5 * * * *"
                        mono
                    />
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Timezone" error={form.errors.timezone}>
                        <Input value={form.data.timezone} onChange={(event) => form.setData('timezone', event.target.value)} />
                    </Field>
                    <Field label="Grace period" error={form.errors.grace_seconds}>
                        <Input
                            type="number"
                            min={0}
                            value={form.data.grace_seconds}
                            placeholder={String(defaultGrace)}
                            onChange={(event) => form.setData('grace_seconds', event.target.value)}
                            suffix={<span className="text-xs">s</span>}
                        />
                    </Field>
                </div>
                <Field label="Monitor this task" hint="Paused tasks keep reporting but never open issues." inline>
                    <Switch checked={form.data.enabled} onCheckedChange={(checked) => form.setData('enabled', checked)} />
                </Field>
            </form>
        </Dialog>
    );
}

export function HeartbeatTable({
    monitors,
    canManage,
    defaultGrace,
    showSite = false,
}: {
    monitors: Row[];
    canManage: boolean;
    defaultGrace: number;
    showSite?: boolean;
}) {
    const [editing, setEditing] = useState<HeartbeatMonitor | null>(null);
    const [deleting, setDeleting] = useState<HeartbeatMonitor | null>(null);
    const hasSlots = monitors.some((monitor) => monitor.slots !== undefined);

    const columns: DataTableColumn<Row>[] = [
        {
            id: 'job',
            header: 'Task',
            sortValue: (monitor) => monitor.job,
            cell: (monitor) => {
                const state = monitorState(monitor);

                return (
                    <span className="flex min-w-0 items-center gap-2.5 py-1.5">
                        <StatusDot status={state.status} label={state.label} />
                        <span className="grid min-w-0 gap-0.5">
                            <span className={cn('truncate font-mono text-xs', monitor.enabled ? 'text-fg' : 'text-fg-muted')}>{monitor.job}</span>
                            <span className="text-fg-faint flex flex-wrap items-center gap-x-2 text-xs">
                                {showSite && <span>{monitor.site_name ?? 'Server task'}</span>}
                                <span className="font-mono">{monitor.schedule ?? 'no schedule'}</span>
                                {monitor.timezone !== 'UTC' && <span>{monitor.timezone}</span>}
                                {monitor.missed_at && (
                                    <Tag tone="danger">
                                        missed <RelativeTime value={monitor.missed_at} />
                                    </Tag>
                                )}
                                {!monitor.enabled && <Tag tone="faint">paused</Tag>}
                            </span>
                        </span>
                    </span>
                );
            },
        },
        ...(hasSlots
            ? [
                  {
                      id: 'expected',
                      header: 'Expected vs actual · 24h',
                      width: '34%',
                      hideOnMobile: true,
                      cell: (monitor: Row) => (
                          <span className="grid gap-1">
                              <HeartbeatStrip slots={monitor.slots ?? []} />
                              <span className="text-fg-faint tabular text-2xs">
                                  {monitor.expected_24h === null || monitor.expected_24h === undefined
                                      ? `${monitor.actual_24h ?? 0} runs · no schedule to compare`
                                      : `${monitor.actual_24h ?? 0} of ${monitor.expected_24h} expected runs`}
                                  {(monitor.missed_24h ?? 0) > 0 && <span className="text-danger"> · {monitor.missed_24h} missed</span>}
                                  {(() => {
                                      const failed = (monitor.slots ?? []).filter(
                                          (slot) => slot.status === 'failed' || slot.status === 'timeout',
                                      ).length;

                                      return failed > 0 ? <span className="text-danger"> · {failed} failed</span> : null;
                                  })()}
                              </span>
                          </span>
                      ),
                  } satisfies DataTableColumn<Row>,
              ]
            : []),
        {
            id: 'last',
            header: 'Last run',
            sortValue: (monitor) => monitor.last_run_at,
            cell: (monitor) => (
                <span className="grid gap-0.5 text-xs">
                    <RelativeTime value={monitor.last_run_at} fallback="never" className="text-fg" />
                    {monitor.last_status && (
                        <span className={monitor.last_status === 'finished' ? 'text-fg-faint' : 'text-danger'}>
                            {monitor.last_status}
                            {monitor.last_duration_ms !== null && ` · ${formatMs(monitor.last_duration_ms)}`}
                        </span>
                    )}
                </span>
            ),
        },
        {
            id: 'next',
            header: 'Next expected',
            hideOnMobile: true,
            sortValue: (monitor) => monitor.next_expected_at,
            cell: (monitor) => (
                <span className="text-fg-muted text-xs">
                    {monitor.next_expected_at ? format(new Date(monitor.next_expected_at), 'MMM d, HH:mm') : '—'}
                </span>
            ),
        },
    ];

    return (
        <>
            <DataTable
                label="Scheduled task heartbeats"
                columns={columns}
                rows={monitors}
                rowKey={(monitor) => monitor.id}
                rowActions={
                    canManage
                        ? (monitor) => [
                              { label: 'Edit schedule', icon: <Pencil />, onSelect: () => setEditing(monitor) },
                              { type: 'separator' },
                              { label: 'Stop tracking', icon: <Trash2 />, danger: true, onSelect: () => setDeleting(monitor) },
                          ]
                        : undefined
                }
                empty={{
                    icon: <HeartPulse />,
                    title: 'No scheduled tasks yet',
                    description:
                        'Every run of a Falak-managed cron job (Laravel scheduler, cron entries) reports a heartbeat. Tasks appear here after their first run; missed runs open issues.',
                }}
            />
            {editing && <EditDialog monitor={editing} defaultGrace={defaultGrace} onClose={() => setEditing(null)} />}
            {deleting && (
                <Dialog
                    open
                    onOpenChange={(open) => !open && setDeleting(null)}
                    size="sm"
                    title={`Stop tracking ${deleting.job}?`}
                    description="Its run history is deleted. The task reappears on its next heartbeat."
                    footer={
                        <>
                            <Button variant="ghost" onClick={() => setDeleting(null)}>
                                Cancel
                            </Button>
                            <Button
                                variant="danger"
                                onClick={() =>
                                    router.delete(route('insights.heartbeats.destroy', deleting.id), {
                                        preserveScroll: true,
                                        onSuccess: () => setDeleting(null),
                                    })
                                }
                            >
                                Stop tracking
                            </Button>
                        </>
                    }
                />
            )}
        </>
    );
}
