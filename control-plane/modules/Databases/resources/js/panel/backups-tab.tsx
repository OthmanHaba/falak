import {
    Button,
    DataTable,
    Dialog,
    EmptyState,
    Field,
    Input,
    RelativeTime,
    Section,
    Select,
    SkeletonRows,
    StatusBadge,
    Switch,
    Tag,
    formatDuration,
} from '@/components/falak';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { type ServiceTabProps } from '@/lib/registry';
import { Link } from '@inertiajs/react';
import { Archive, CalendarClock, Play, Plus, RotateCcw, Trash2 } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { type BackupRow, type ScheduleRow } from '../types';
import { formatBytes, mutate, useDatabasePanel, type DatabasePanelData } from './api';

function RestoreDialog({
    backup,
    data,
    onClose,
    reload,
}: {
    backup: BackupRow | null;
    data: DatabasePanelData;
    onClose: () => void;
    reload: () => Promise<void>;
}) {
    const [target, setTarget] = useState(data.server.id);
    const [name, setName] = useState(data.database.name);
    const [confirm, setConfirm] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [running, setRunning] = useState(false);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (!backup) return;
        setRunning(true);
        try {
            await requestJson(`/databases/backups/${backup.id}/restore`, 'POST', { database_server_id: target, database: name, confirm });
            await reload();
            onClose();
        } catch (e) {
            setError(e instanceof HttpError ? (Object.values(e.errors)[0] ?? e.message) : errorMessage(e));
        } finally {
            setRunning(false);
        }
    };

    return (
        <Dialog
            open={backup !== null}
            onOpenChange={(open) => !open && onClose()}
            title="Restore backup"
            description={
                backup
                    ? `Restores the ${new Date(backup.created_at).toLocaleString()} backup of ${backup.database_name}. The target database is overwritten.`
                    : undefined
            }
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="danger" type="submit" form="restore-backup" loading={running} disabled={confirm !== name || !name}>
                        Restore
                    </Button>
                </>
            }
        >
            <form id="restore-backup" onSubmit={submit} className="grid gap-4">
                <Field label="Target engine server">
                    <Select
                        value={target}
                        onValueChange={setTarget}
                        options={data.restore_targets.map((item) => ({ value: item.id, label: item.label }))}
                    />
                </Field>
                <Field label="Target database" hint="Created when it does not exist.">
                    <Input value={name} onChange={(event) => setName(event.target.value)} mono />
                </Field>
                <Field
                    label={
                        <>
                            Type <span className="text-fg font-mono">{name}</span> to confirm
                        </>
                    }
                    error={error}
                >
                    <Input value={confirm} onChange={(event) => setConfirm(event.target.value)} mono autoComplete="off" />
                </Field>
            </form>
        </Dialog>
    );
}

function ScheduleForm({ data, onDone, reload }: { data: DatabasePanelData; onDone: () => void; reload: () => Promise<void> }) {
    const [form, setForm] = useState({
        name: 'Nightly',
        cron: '0 3 * * *',
        storage_provider_id: data.storage_providers[0]?.id ?? '',
        retention_count: '14',
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        try {
            await requestJson(`/databases/servers/${data.server.id}/schedules`, 'POST', {
                ...form,
                retention_count: form.retention_count ? Number(form.retention_count) : null,
                database_ids: [data.database.id],
                compression: 'gzip',
                enabled: true,
            });
            await reload();
            onDone();
        } catch (e) {
            setErrors(e instanceof HttpError ? e.errors : { form: errorMessage(e) });
        } finally {
            setSaving(false);
        }
    };

    return (
        <form onSubmit={submit} className="border-border bg-surface-1 grid gap-3 rounded-lg border p-4 sm:grid-cols-2">
            <Field label="Name" error={errors.name}>
                <Input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
            </Field>
            <Field label="Schedule (cron)" hint="0 3 * * * = every day at 03:00 UTC" error={errors.cron}>
                <Input value={form.cron} onChange={(event) => setForm({ ...form, cron: event.target.value })} mono />
            </Field>
            <Field label="Storage" error={errors.storage_provider_id}>
                <Select
                    value={form.storage_provider_id}
                    onValueChange={(value) => setForm({ ...form, storage_provider_id: value })}
                    options={data.storage_providers.map((provider) => ({ value: provider.id, label: `${provider.name} · ${provider.bucket}` }))}
                />
            </Field>
            <Field label="Keep last" hint="Backups kept per database" error={errors.retention_count}>
                <Input
                    value={form.retention_count}
                    onChange={(event) => setForm({ ...form, retention_count: event.target.value.replace(/\D/g, '') })}
                    inputMode="numeric"
                />
            </Field>
            {errors.form && <p className="text-danger text-xs sm:col-span-2">{errors.form}</p>}
            <div className="flex justify-end gap-2 sm:col-span-2">
                <Button variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
                <Button variant="primary" type="submit" loading={saving}>
                    Create schedule
                </Button>
            </div>
        </form>
    );
}

/** §5.4 Backups: back up now, schedules, history with restore. */
export function DatabaseBackupsTab({ ctx }: ServiceTabProps) {
    const { data, error, reload } = useDatabasePanel(ctx);
    const [storage, setStorage] = useState<string | null>(null);
    const [addingSchedule, setAddingSchedule] = useState(false);
    const [restoring, setRestoring] = useState<BackupRow | null>(null);
    const [backingUp, setBackingUp] = useState(false);

    if (!data) return error ? <p className="text-danger text-sm">{error}</p> : <SkeletonRows rows={6} />;

    const { can } = data;
    const provider = storage ?? data.storage_providers[0]?.id ?? null;

    if (data.storage_providers.length === 0 && data.backups.length === 0) {
        return (
            <EmptyState
                icon={<Archive />}
                title="No backup storage yet"
                description="Backups go to S3-compatible storage (S3, R2, B2, Spaces, MinIO). Add a storage provider, then back up now or on a schedule."
                action={
                    can.manage_storage && (
                        <Button asChild variant="primary">
                            <Link href="/settings/storage">Add storage</Link>
                        </Button>
                    )
                }
            />
        );
    }

    const toggle = (schedule: ScheduleRow, enabled: boolean) =>
        mutate(
            'PUT',
            `/databases/schedules/${schedule.id}`,
            {
                name: schedule.name,
                storage_provider_id: schedule.storage_provider_id,
                database_ids: schedule.database_ids,
                cron: schedule.cron,
                retention_count: schedule.retention_count,
                retention_days: schedule.retention_days,
                compression: schedule.compression,
                enabled,
            },
            { success: enabled ? 'Schedule enabled' : 'Schedule paused', reload },
        );

    return (
        <div className="grid gap-8">
            {can.manage && (
                <div className="border-border bg-surface-1 flex flex-wrap items-center gap-3 rounded-lg border p-3">
                    <Archive className="text-fg-muted size-4" aria-hidden />
                    <span className="text-fg flex-1 text-sm">Back up {data.database.name} now</span>
                    {data.storage_providers.length > 1 && (
                        <Select
                            size="sm"
                            aria-label="Storage"
                            value={provider ?? undefined}
                            onValueChange={setStorage}
                            options={data.storage_providers.map((item) => ({ value: item.id, label: item.name }))}
                        />
                    )}
                    <Button
                        size="sm"
                        variant="primary"
                        loading={backingUp}
                        disabled={!provider}
                        onClick={async () => {
                            setBackingUp(true);
                            await mutate(
                                'POST',
                                `/databases/databases/${data.database.id}/backups`,
                                { storage_provider_id: provider, compression: 'gzip' },
                                { success: 'Backup started', reload },
                            );
                            setBackingUp(false);
                        }}
                    >
                        Back up now
                    </Button>
                </div>
            )}

            <Section
                title="Schedules"
                description="Automatic backups of this database."
                aside={
                    can.manage &&
                    !addingSchedule &&
                    data.storage_providers.length > 0 && (
                        <Button size="sm" icon={<Plus />} onClick={() => setAddingSchedule(true)}>
                            New schedule
                        </Button>
                    )
                }
                bare
            >
                {addingSchedule && <ScheduleForm data={data} reload={reload} onDone={() => setAddingSchedule(false)} />}
                <DataTable
                    label="Backup schedules"
                    rows={data.schedules}
                    rowKey={(schedule) => schedule.id}
                    empty={{
                        icon: <CalendarClock />,
                        title: 'No schedule',
                        description: 'Nightly backups with a retention policy protect you from bad migrations.',
                        size: 'sm',
                    }}
                    columns={[
                        {
                            id: 'name',
                            header: 'Schedule',
                            cell: (schedule) => (
                                <span className="grid">
                                    <span className="text-fg">{schedule.name}</span>
                                    <span className="text-fg-faint font-mono text-xs">{schedule.cron}</span>
                                </span>
                            ),
                        },
                        { id: 'storage', header: 'Storage', hideOnMobile: true, cell: (schedule) => schedule.storage_provider ?? '—' },
                        {
                            id: 'retention',
                            header: 'Keeps',
                            hideOnMobile: true,
                            cell: (schedule) =>
                                [
                                    schedule.retention_count && `${schedule.retention_count} backups`,
                                    schedule.retention_days && `${schedule.retention_days} days`,
                                ]
                                    .filter(Boolean)
                                    .join(' · ') || 'all',
                        },
                        {
                            id: 'next',
                            header: 'Next run',
                            cell: (schedule) => (schedule.enabled ? <RelativeTime value={schedule.next_run_at} /> : <Tag>paused</Tag>),
                        },
                        {
                            id: 'enabled',
                            header: <span className="sr-only">Enabled</span>,
                            align: 'right',
                            cell: (schedule) => (
                                <Switch
                                    checked={schedule.enabled}
                                    disabled={!can.manage}
                                    onCheckedChange={(value) => void toggle(schedule, value)}
                                    aria-label={`${schedule.name} enabled`}
                                />
                            ),
                        },
                    ]}
                    rowActions={
                        can.manage
                            ? (schedule) => [
                                  {
                                      label: 'Run now',
                                      icon: <Play />,
                                      onSelect: () =>
                                          void mutate('POST', `/databases/schedules/${schedule.id}/run`, {}, { success: 'Backup started', reload }),
                                  },
                                  { type: 'separator' },
                                  {
                                      label: 'Delete schedule',
                                      icon: <Trash2 />,
                                      danger: true,
                                      onSelect: () =>
                                          void mutate('DELETE', `/databases/schedules/${schedule.id}`, undefined, {
                                              success: 'Schedule deleted',
                                              reload,
                                          }),
                                  },
                              ]
                            : undefined
                    }
                />
            </Section>

            <Section title="History" description="The last 50 backups of this database." bare>
                <DataTable
                    label="Backups"
                    rows={data.backups}
                    rowKey={(backup) => backup.id}
                    empty={{ icon: <Archive />, title: 'No backups yet', description: 'Back up now or add a schedule.', size: 'sm' }}
                    columns={[
                        { id: 'status', header: 'Status', cell: (backup) => <StatusBadge status={backup.status} /> },
                        {
                            id: 'created',
                            header: 'Taken',
                            cell: (backup) => <RelativeTime value={backup.created_at} />,
                            sortValue: (backup) => backup.created_at,
                        },
                        { id: 'trigger', header: 'Trigger', hideOnMobile: true, cell: (backup) => <Tag>{backup.trigger}</Tag> },
                        {
                            id: 'size',
                            header: 'Size',
                            align: 'right',
                            cell: (backup) => <span className="tabular">{formatBytes(backup.size_bytes)}</span>,
                        },
                        {
                            id: 'duration',
                            header: 'Took',
                            align: 'right',
                            hideOnMobile: true,
                            cell: (backup) => <span className="tabular">{formatDuration(backup.duration_ms) || '—'}</span>,
                        },
                        { id: 'storage', header: 'Storage', hideOnMobile: true, cell: (backup) => backup.storage_provider ?? '—' },
                    ]}
                    rowActions={(backup) => [
                        ...(can.restore && backup.restorable
                            ? [{ label: 'Restore…', icon: <RotateCcw />, onSelect: () => setRestoring(backup) }]
                            : []),
                        ...(can.manage
                            ? [
                                  { type: 'separator' as const },
                                  {
                                      label: 'Delete backup',
                                      icon: <Trash2 />,
                                      danger: true,
                                      onSelect: () =>
                                          void mutate('DELETE', `/databases/backups/${backup.id}`, undefined, { success: 'Backup deleted', reload }),
                                  },
                              ]
                            : []),
                    ]}
                />
            </Section>

            {data.restores.length > 0 && (
                <Section title="Restores" bare>
                    <DataTable
                        label="Restores"
                        rows={data.restores}
                        rowKey={(restore) => restore.id}
                        columns={[
                            { id: 'status', header: 'Status', cell: (restore) => <StatusBadge status={restore.status} /> },
                            {
                                id: 'what',
                                header: 'Restore',
                                cell: (restore) => (
                                    <span className="font-mono text-xs">
                                        {restore.source_database} → {restore.database_name}
                                    </span>
                                ),
                            },
                            { id: 'created', header: 'Started', cell: (restore) => <RelativeTime value={restore.created_at} /> },
                            {
                                id: 'error',
                                header: 'Error',
                                hideOnMobile: true,
                                cell: (restore) => <span className="text-danger text-xs">{restore.error}</span>,
                            },
                        ]}
                    />
                </Section>
            )}

            <RestoreDialog key={restoring?.id ?? 'none'} backup={restoring} data={data} reload={reload} onClose={() => setRestoring(null)} />
        </div>
    );
}
