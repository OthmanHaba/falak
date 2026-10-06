import { Button, Callout, DataTable, Dialog, Field, Input, RelativeTime, Section, Select, StatusBadge, Switch, Tag } from '@/components/falak';
import { formatBytes } from '@/lib/utils';
import { Archive, CalendarClock, Pencil, Plus, RotateCcw, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    CONSISTENCY_OPTIONS,
    GIB,
    type BackupSchedule,
    type Consistency,
    type ServerOption,
    type StorageProvider,
    type Volume,
    type VolumeBackup,
} from '../types';
import { useVolumeAction } from './api';

interface Props {
    volume: Volume;
    backups: VolumeBackup[];
    schedules: BackupSchedule[];
    providers: StorageProvider[];
    servers: ServerOption[];
    canManage: boolean;
}

interface ScheduleForm {
    storage_provider_id: string;
    cron: string;
    retention_count: string;
    retention_days: string;
    consistency: Consistency;
    enabled: boolean;
}

/** Backups of a volume: run one now, schedules with retention, the snapshots with restore (into a new volume). */
export function BackupsSection({ volume, backups, schedules, providers, servers, canManage }: Props) {
    const [backingUp, setBackingUp] = useState(false);
    const [editing, setEditing] = useState<BackupSchedule | 'new' | null>(null);
    const [restoring, setRestoring] = useState<VolumeBackup | null>(null);
    const [deleting, setDeleting] = useState<VolumeBackup | null>(null);
    const [now, setNow] = useState({ storage_provider_id: providers[0]?.id ?? '', consistency: 'none' as Consistency });
    const [schedule, setSchedule] = useState<ScheduleForm>(emptySchedule(providers));
    const [restore, setRestore] = useState({ server_id: '', name: '', size_gib: '', swap: false });
    const action = useVolumeAction();
    const providerName = (id: string | null) => providers.find((provider) => provider.id === id)?.name ?? 'Deleted storage';

    useEffect(() => {
        if (editing === 'new') setSchedule(emptySchedule(providers));
        else if (editing)
            setSchedule({
                storage_provider_id: editing.storage_provider_id ?? providers[0]?.id ?? '',
                cron: editing.cron,
                retention_count: editing.retention_count?.toString() ?? '',
                retention_days: editing.retention_days?.toString() ?? '',
                consistency: editing.consistency,
                enabled: editing.enabled,
            });
        action.setErrors({});
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [editing]);

    useEffect(() => {
        if (restoring) {
            setRestore({
                server_id: volume.server?.id ?? servers[0]?.id ?? '',
                name: `${restoring.volume_name}-restored`.slice(0, 63),
                size_gib: '',
                swap: false,
            });
            action.setErrors({});
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [restoring]);

    if (volume.kind !== 'docker' && volume.kind !== 'sized') {
        return (
            <Section title="Backups" description="Only Docker and sized volumes are backed up by Falak.">
                <p className="text-fg-muted text-sm">
                    {volume.kind === 'shared_path' ? 'Shared paths live with their site on every server.' : 'Host paths are the server’s own files.'}
                </p>
            </Section>
        );
    }

    const saveSchedule = async () => {
        const body = {
            ...schedule,
            retention_count: schedule.retention_count ? Number(schedule.retention_count) : null,
            retention_days: schedule.retention_days ? Number(schedule.retention_days) : null,
        };
        const ok =
            editing === 'new'
                ? await action.run('POST', `/volumes/${volume.id}/schedules`, body, 'Schedule saved')
                : await action.run('PUT', `/volumes/schedules/${(editing as BackupSchedule).id}`, body, 'Schedule saved');
        if (ok !== null) setEditing(null);
    };

    return (
        <Section
            title="Backups"
            description="Consistent tar | zstd snapshots in your backup storage, restored into a new volume (never over live data)."
            bare
            aside={
                canManage &&
                providers.length > 0 && (
                    <>
                        <Button size="sm" icon={<CalendarClock />} onClick={() => setEditing('new')}>
                            Add schedule
                        </Button>
                        <Button
                            size="sm"
                            variant="primary"
                            icon={<Archive />}
                            onClick={() => setBackingUp(true)}
                            disabled={volume.status !== 'active'}
                        >
                            Back up now
                        </Button>
                    </>
                )
            }
        >
            {providers.length === 0 && <Callout tone="info">Add backup storage (Settings → Backup storage) to back up volumes.</Callout>}

            {schedules.length > 0 && (
                <DataTable<BackupSchedule>
                    label="Backup schedules"
                    rows={schedules}
                    rowKey={(row) => row.id}
                    columns={[
                        {
                            id: 'cron',
                            header: 'Schedule (UTC)',
                            cell: (row) => (
                                <span className="flex items-center gap-2">
                                    <span className="font-mono text-xs">{row.cron}</span>
                                    {!row.enabled && <Tag tone="faint">Paused</Tag>}
                                </span>
                            ),
                        },
                        { id: 'storage', header: 'Storage', cell: (row) => <span className="text-sm">{providerName(row.storage_provider_id)}</span> },
                        {
                            id: 'retention',
                            header: 'Keeps',
                            hideOnMobile: true,
                            cell: (row) => (
                                <span className="text-fg-muted text-xs">
                                    {[row.retention_count && `${row.retention_count} backups`, row.retention_days && `${row.retention_days} days`]
                                        .filter(Boolean)
                                        .join(' · ') || 'Everything'}
                                </span>
                            ),
                        },
                        { id: 'next', header: 'Next run', hideOnMobile: true, cell: (row) => <RelativeTime value={row.next_run_at} fallback="—" /> },
                    ]}
                    rowActions={
                        canManage
                            ? (row) => [
                                  { label: 'Edit', icon: <Pencil />, onSelect: () => setEditing(row) },
                                  { type: 'separator' },
                                  {
                                      label: 'Delete schedule',
                                      icon: <Trash2 />,
                                      danger: true,
                                      onSelect: () => void action.run('DELETE', `/volumes/schedules/${row.id}`, undefined, 'Schedule deleted'),
                                  },
                              ]
                            : undefined
                    }
                />
            )}

            <DataTable<VolumeBackup>
                label="Backups"
                rows={backups}
                rowKey={(row) => row.id}
                empty={{ icon: <Archive />, title: 'No backups yet', description: 'Back up now or add a schedule.', size: 'sm' }}
                columns={[
                    {
                        id: 'created',
                        header: 'Taken',
                        cell: (row) => (
                            <span className="flex items-center gap-2">
                                <RelativeTime value={row.created_at} />
                                {row.trigger !== 'manual' && <Tag tone="faint">{row.trigger}</Tag>}
                            </span>
                        ),
                    },
                    {
                        id: 'size',
                        header: 'Size',
                        align: 'right',
                        cell: (row) => <span className="text-fg-muted tabular text-xs">{formatBytes(row.size_bytes)}</span>,
                    },
                    {
                        id: 'storage',
                        header: 'Storage',
                        hideOnMobile: true,
                        cell: (row) => <span className="text-sm">{providerName(row.storage_provider_id)}</span>,
                    },
                    {
                        id: 'status',
                        header: 'Status',
                        align: 'right',
                        cell: (row) => (
                            <span title={row.error ?? undefined}>
                                <StatusBadge
                                    status={row.status === 'pruned' ? 'removed' : row.status}
                                    label={row.status === 'pruned' ? 'Pruned' : undefined}
                                />
                            </span>
                        ),
                    },
                ]}
                rowActions={
                    canManage
                        ? (row) => [
                              {
                                  label: 'Restore into a new volume',
                                  icon: <RotateCcw />,
                                  disabled: !row.restorable,
                                  onSelect: () => setRestoring(row),
                              },
                              { type: 'separator' },
                              {
                                  label: 'Delete backup',
                                  icon: <Trash2 />,
                                  danger: true,
                                  disabled: row.status === 'pending',
                                  onSelect: () => setDeleting(row),
                              },
                          ]
                        : undefined
                }
            />

            <Dialog
                open={backingUp}
                onOpenChange={setBackingUp}
                title={`Back up ${volume.name}`}
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setBackingUp(false)}>
                            Cancel
                        </Button>
                        <Button
                            variant="primary"
                            loading={action.busy}
                            disabled={!now.storage_provider_id}
                            onClick={async () => {
                                if ((await action.run('POST', `/volumes/${volume.id}/backups`, now, 'Backup started')) !== null) setBackingUp(false);
                            }}
                        >
                            Back up
                        </Button>
                    </>
                }
            >
                <div className="grid gap-4">
                    <Field label="Storage" error={action.errors.storage_provider_id}>
                        <Select
                            value={now.storage_provider_id}
                            onValueChange={(value) => setNow({ ...now, storage_provider_id: value })}
                            options={providerOptions(providers)}
                        />
                    </Field>
                    <Field
                        label="Consistency"
                        error={action.errors.consistency}
                        hint="Pausing or stopping the services that write to it gives a consistent snapshot."
                    >
                        <Select
                            value={now.consistency}
                            onValueChange={(value) => setNow({ ...now, consistency: value })}
                            options={CONSISTENCY_OPTIONS}
                        />
                    </Field>
                </div>
            </Dialog>

            <Dialog
                open={editing !== null}
                onOpenChange={(open) => !open && setEditing(null)}
                title={editing === 'new' ? 'Add backup schedule' : 'Edit backup schedule'}
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setEditing(null)}>
                            Cancel
                        </Button>
                        <Button
                            variant="primary"
                            loading={action.busy}
                            disabled={!schedule.cron.trim() || !schedule.storage_provider_id}
                            onClick={() => void saveSchedule()}
                        >
                            Save schedule
                        </Button>
                    </>
                }
            >
                <div className="grid gap-4">
                    <Field label="Cron (UTC)" required error={action.errors.cron} hint="e.g. 0 3 * * * for every day at 03:00 UTC.">
                        <Input mono value={schedule.cron} onChange={(event) => setSchedule({ ...schedule, cron: event.target.value })} />
                    </Field>
                    <Field label="Storage" error={action.errors.storage_provider_id}>
                        <Select
                            value={schedule.storage_provider_id}
                            onValueChange={(value) => setSchedule({ ...schedule, storage_provider_id: value })}
                            options={providerOptions(providers)}
                        />
                    </Field>
                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Keep at most" error={action.errors.retention_count}>
                            <Input
                                type="number"
                                min={1}
                                value={schedule.retention_count}
                                onChange={(event) => setSchedule({ ...schedule, retention_count: event.target.value })}
                                suffix={<span className="text-fg-faint text-xs">backups</span>}
                            />
                        </Field>
                        <Field label="Keep for" error={action.errors.retention_days}>
                            <Input
                                type="number"
                                min={1}
                                value={schedule.retention_days}
                                onChange={(event) => setSchedule({ ...schedule, retention_days: event.target.value })}
                                suffix={<span className="text-fg-faint text-xs">days</span>}
                            />
                        </Field>
                    </div>
                    <Field label="Consistency" error={action.errors.consistency}>
                        <Select
                            value={schedule.consistency}
                            onValueChange={(value) => setSchedule({ ...schedule, consistency: value })}
                            options={CONSISTENCY_OPTIONS}
                        />
                    </Field>
                    <Field label="Enabled" inline>
                        <Switch checked={schedule.enabled} onCheckedChange={(checked) => setSchedule({ ...schedule, enabled: checked })} />
                    </Field>
                </div>
            </Dialog>

            <Dialog
                open={restoring !== null}
                onOpenChange={(open) => !open && setRestoring(null)}
                title="Restore into a new volume"
                description="The backup is unpacked into a new volume after its checksum is verified. The current volume is not touched."
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setRestoring(null)}>
                            Cancel
                        </Button>
                        <Button
                            variant="primary"
                            loading={action.busy}
                            disabled={!restore.name.trim() || !restore.server_id}
                            onClick={async () => {
                                const body = {
                                    server_id: restore.server_id,
                                    name: restore.name.trim(),
                                    swap: restore.swap,
                                    ...(restore.size_gib ? { size_bytes: Math.round(Number(restore.size_gib) * GIB) } : {}),
                                };
                                if ((await action.run('POST', `/volumes/backups/${restoring?.id}/restore`, body, 'Restore started')) !== null)
                                    setRestoring(null);
                            }}
                        >
                            Restore
                        </Button>
                    </>
                }
            >
                <div className="grid gap-4">
                    <Field label="Server" error={action.errors.server_id}>
                        <Select
                            value={restore.server_id}
                            onValueChange={(value) => setRestore({ ...restore, server_id: value, swap: value === volume.server?.id && restore.swap })}
                            options={servers.map((server) => ({ value: server.id, label: server.name }))}
                        />
                    </Field>
                    <Field label="New volume name" required error={action.errors.name}>
                        <Input mono value={restore.name} onChange={(event) => setRestore({ ...restore, name: event.target.value })} />
                    </Field>
                    {restoring?.volume_kind === 'sized' && (
                        <Field
                            label="Size"
                            error={action.errors.size_bytes}
                            hint={`At least ${formatBytes(Math.max(restoring.volume_size_bytes ?? 0, Math.ceil((restoring.uncompressed_bytes ?? 0) * 1.2)))} (leave empty for that).`}
                        >
                            <Input
                                type="number"
                                min={1}
                                value={restore.size_gib}
                                onChange={(event) => setRestore({ ...restore, size_gib: event.target.value })}
                                suffix={<span className="text-fg-faint text-xs">GiB</span>}
                            />
                        </Field>
                    )}
                    <Field
                        label="Swap it in"
                        inline
                        error={action.errors.swap}
                        hint="When ready, the services using this volume switch to the restored one and redeploy. Same server only."
                    >
                        <Switch
                            checked={restore.swap}
                            disabled={restore.server_id !== volume.server?.id || restoring?.volume_id !== volume.id}
                            onCheckedChange={(checked) => setRestore({ ...restore, swap: checked })}
                        />
                    </Field>
                </div>
            </Dialog>

            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                size="sm"
                title="Delete this backup?"
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setDeleting(null)}>
                            Cancel
                        </Button>
                        <Button
                            variant="danger"
                            loading={action.busy}
                            onClick={async () => {
                                if ((await action.run('DELETE', `/volumes/backups/${deleting?.id}`, undefined, 'Backup deleted')) !== null)
                                    setDeleting(null);
                            }}
                        >
                            Delete backup
                        </Button>
                    </>
                }
            >
                <p className="text-fg-muted text-sm">
                    The snapshot is removed from {providerName(deleting?.storage_provider_id ?? null)}. This cannot be undone.
                </p>
            </Dialog>

            {canManage && providers.length > 0 && schedules.length === 0 && (
                <p className="text-fg-faint flex items-center gap-1 text-xs">
                    <Plus className="size-3" aria-hidden /> No schedule: back it up regularly with Add schedule.
                </p>
            )}
        </Section>
    );
}

function emptySchedule(providers: StorageProvider[]): ScheduleForm {
    return {
        storage_provider_id: providers[0]?.id ?? '',
        cron: '0 3 * * *',
        retention_count: '7',
        retention_days: '',
        consistency: 'none',
        enabled: true,
    };
}

function providerOptions(providers: StorageProvider[]) {
    return providers.map((provider) => ({ value: provider.id, label: `${provider.name} (${provider.bucket})` }));
}
