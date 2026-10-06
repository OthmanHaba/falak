import { Button, DataTable, Dialog, Field, Input, RelativeTime, Select, StatusBadge, Switch, Tag, Tooltip, toast } from '@/components/falak';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { cn, formatBytes } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { HardDrive, Lock, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { GIB, kindLabel, volumeStatus, type CreationProps, type Volume, type VolumeKind } from '../types';

/** used / limit as a bar (sized volumes), or just the bytes used. */
export function UsageBar({
    volume,
    className,
}: {
    volume: Pick<Volume, 'used_bytes' | 'size_limit_bytes' | 'usage' | 'used_at'>;
    className?: string;
}) {
    if (volume.size_limit_bytes === null) {
        return (
            <span
                className={cn('text-fg-muted tabular text-xs', className)}
                title={volume.used_at ? `Measured ${new Date(volume.used_at).toLocaleString()}` : undefined}
            >
                {volume.used_bytes !== null ? formatBytes(volume.used_bytes) : 'Not measured yet'}
            </span>
        );
    }

    const usage = Math.min(1, volume.usage ?? 0);

    return (
        <div className={cn('grid min-w-28 gap-1', className)}>
            <div
                className="bg-surface-3 h-1.5 overflow-hidden rounded-full"
                role="meter"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={Math.round(usage * 100)}
                aria-label="Used"
            >
                <div
                    className={cn('h-full rounded-full', usage > 0.85 ? 'bg-warning' : 'bg-primary')}
                    style={{ width: `${Math.max(usage * 100, 1)}%` }}
                />
            </div>
            <span className="text-fg-muted tabular text-xs">
                {formatBytes(volume.used_bytes)} / {formatBytes(volume.size_limit_bytes)}
            </span>
        </div>
    );
}

/** Where the volume is attached, as links to the services. */
export function AttachedTo({ volume }: { volume: Volume }) {
    if (volume.attachments.length === 0) return <span className="text-fg-faint text-xs">Not attached</span>;
    const [first, ...rest] = volume.attachments;

    return (
        <span className="flex min-w-0 items-center gap-1.5 text-sm">
            {first.url ? (
                <Link
                    href={first.url}
                    className="hover:text-fg truncate underline-offset-2 hover:underline"
                    onClick={(event) => event.stopPropagation()}
                >
                    {first.name}
                </Link>
            ) : (
                <span className="truncate">{first.name}</span>
            )}
            {rest.length > 0 && (
                <Tooltip content={rest.map((attachment) => attachment.name).join('\n')}>
                    <span className="text-fg-faint shrink-0 text-xs">+{rest.length}</span>
                </Tooltip>
            )}
        </span>
    );
}

/** A list of volumes (server and project pages); each row opens the volume. */
export function VolumesTable({ volumes, showServer, onCreate }: { volumes: Volume[]; showServer?: boolean; onCreate?: () => void }) {
    return (
        <DataTable<Volume>
            label="Volumes"
            rows={volumes}
            rowKey={(row) => row.id}
            onRowClick={(row) => router.visit(row.url)}
            defaultSort={{ column: 'name', direction: 'asc' }}
            empty={{
                icon: <HardDrive />,
                title: 'No volumes',
                description:
                    'Volumes keep data across deploys: compose stacks declare theirs, shared paths of classic sites are volumes too, and container services mount the ones you attach.',
                action: onCreate ? (
                    <Button variant="primary" icon={<Plus />} onClick={onCreate}>
                        New volume
                    </Button>
                ) : undefined,
            }}
            columns={[
                {
                    id: 'name',
                    header: 'Volume',
                    sortValue: (row) => row.name,
                    cell: (row) => (
                        <span className="flex min-w-0 items-center gap-2">
                            <HardDrive className="text-fg-faint size-3.5 shrink-0" aria-hidden />
                            <span className="text-fg truncate font-mono text-xs font-medium">{row.name}</span>
                            {row.protected && <Lock className="text-fg-faint size-3 shrink-0" aria-label="Protected" />}
                            <Tag tone="faint">{kindLabel(row.kind)}</Tag>
                        </span>
                    ),
                },
                ...(showServer
                    ? [
                          {
                              id: 'server',
                              header: 'Server',
                              hideOnMobile: true,
                              sortValue: (row: Volume) => row.server?.name ?? '',
                              cell: (row: Volume) => <span className="text-fg-muted text-sm">{row.server?.name ?? 'Every server of its site'}</span>,
                          },
                      ]
                    : []),
                {
                    id: 'usage',
                    header: 'Usage',
                    sortValue: (row) => row.usage ?? row.used_bytes ?? -1,
                    cell: (row) => <UsageBar volume={row} />,
                },
                {
                    id: 'attached',
                    header: 'Attached to',
                    hideOnMobile: true,
                    cell: (row) => <AttachedTo volume={row} />,
                },
                {
                    id: 'backups',
                    header: 'Backups',
                    hideOnMobile: true,
                    sortValue: (row) => row.last_backup_at ?? '',
                    cell: (row) =>
                        row.kind === 'docker' || row.kind === 'sized' ? (
                            <span className="text-fg-muted text-xs">
                                {row.schedules > 0 ? `${row.schedules} schedule${row.schedules > 1 ? 's' : ''} · ` : ''}
                                <RelativeTime value={row.last_backup_at} fallback="Never backed up" />
                            </span>
                        ) : (
                            <span className="text-fg-faint text-xs">—</span>
                        ),
                },
                {
                    id: 'status',
                    header: 'Status',
                    align: 'right',
                    cell: (row) => (
                        <StatusBadge status={volumeStatus(row)} label={row.status_message && row.status === 'active' ? 'Attention' : undefined} />
                    ),
                },
            ]}
        />
    );
}

interface NewVolumeForm {
    server_id: string;
    name: string;
    kind: VolumeKind;
    size_gib: string;
    host_path: string;
    protected: boolean;
}

/** "New volume": a Docker volume, a sized volume (hard limit, grows online) or, for admins, a host path. */
export function NewVolumeDialog({
    open,
    onOpenChange,
    creation,
    serverId,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    creation: CreationProps;
    /** Fixed server (the server's Volumes tab). */
    serverId?: string;
}) {
    const initial: NewVolumeForm = {
        server_id: serverId ?? creation.servers[0]?.id ?? '',
        name: '',
        kind: 'sized',
        size_gib: '10',
        host_path: creation.bind_allow[0] ? `${creation.bind_allow[0].replace(/\/$/, '')}/` : '',
        protected: false,
    };
    const [form, setForm] = useState<NewVolumeForm>(initial);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const server = creation.servers.find((item) => item.id === form.server_id);

    useEffect(() => {
        if (open) {
            setForm(initial);
            setErrors({});
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const set = (patch: Partial<NewVolumeForm>) => setForm((current) => ({ ...current, ...patch }));

    const submit = async () => {
        setSaving(true);
        setErrors({});
        try {
            const response = await requestJson<{ data: { url: string } }>('/volumes', 'POST', {
                server_id: form.server_id,
                name: form.name.trim(),
                kind: form.kind,
                ...(form.kind === 'sized' ? { size_bytes: Math.round(Number(form.size_gib) * GIB) } : {}),
                ...(form.kind === 'bind' ? { host_path: form.host_path.trim() } : {}),
                protected: form.protected,
            });
            toast.success(`Volume ${form.name} is being created`);
            onOpenChange(false);
            router.visit(response?.data.url ?? window.location.pathname);
        } catch (error) {
            if (error instanceof HttpError) setErrors(error.errors);
            toast.error('Could not create the volume', errorMessage(error));
        } finally {
            setSaving(false);
        }
    };

    const kinds = [
        { value: 'sized' as const, label: 'Sized volume (hard limit)' },
        ...(server?.docker !== false ? [{ value: 'docker' as const, label: 'Docker volume' }] : []),
        ...(creation.bind_allow.length > 0 ? [{ value: 'bind' as const, label: 'Host path' }] : []),
    ];

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title="New volume"
            description="Attach it to a container service afterwards; attaching redeploys the service."
            footer={
                <>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button variant="primary" loading={saving} disabled={!form.name.trim() || !form.server_id} onClick={() => void submit()}>
                        Create volume
                    </Button>
                </>
            }
        >
            <div className="grid gap-4">
                {!serverId && (
                    <Field label="Server" required error={errors.server_id}>
                        <Select
                            value={form.server_id}
                            onValueChange={(value) => set({ server_id: value })}
                            options={creation.servers.map((item) => ({ value: item.id, label: item.name }))}
                            placeholder="Choose a server"
                        />
                    </Field>
                )}
                <Field label="Name" required error={errors.name} hint="Lowercase letters, digits, “.”, “_” and “-”.">
                    <Input mono value={form.name} onChange={(event) => set({ name: event.target.value })} placeholder="uploads" autoFocus />
                </Field>
                <Field label="Kind" error={errors.kind}>
                    <Select value={form.kind} onValueChange={(value) => set({ kind: value })} options={kinds} />
                </Field>
                {form.kind === 'sized' && (
                    <Field
                        label="Size"
                        required
                        error={errors.size_bytes}
                        hint={`An ext4 image on the server; grow it later without downtime (at most ${formatBytes(creation.limits.max_size_bytes)}).`}
                    >
                        <Input
                            type="number"
                            min={1}
                            step={1}
                            value={form.size_gib}
                            onChange={(event) => set({ size_gib: event.target.value })}
                            suffix={<span className="text-fg-faint text-xs">GiB</span>}
                        />
                    </Field>
                )}
                {form.kind === 'bind' && (
                    <Field label="Host path" required error={errors.host_path} hint={`Inside ${creation.bind_allow.join(', ')}.`}>
                        <Input mono value={form.host_path} onChange={(event) => set({ host_path: event.target.value })} />
                    </Field>
                )}
                {form.kind === 'docker' && <p className="text-fg-muted text-xs">Docker volumes have no size limit: they share the server’s disk.</p>}
                <Field label="Protected" inline hint="A protected volume can never be deleted, not even with its service.">
                    <Switch checked={form.protected} onCheckedChange={(checked) => set({ protected: checked })} />
                </Field>
            </div>
        </Dialog>
    );
}
