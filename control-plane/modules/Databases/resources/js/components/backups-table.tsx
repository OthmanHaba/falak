import { BackupBadges, useKeyExport } from '@/components/backup-protection';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { router, useForm } from '@inertiajs/react';
import { format } from 'date-fns';
import { Download, KeyRound, RotateCcw, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { AGE_IDENTITY, isKeyValue, needsIdentity, retargetRestore, type BackupRow, type RestoreTarget } from '../types';
import { StatusBadge, formatBytes, formatDuration } from './database-ui';

interface Props {
    backups: BackupRow[];
    showServer?: boolean;
    canManage: boolean;
    canRestore: boolean;
    restoreTargets?: RestoreTarget[];
}

/**
 * Backup history with download, restore (typed confirmation) and delete. Redis / Valkey snapshots restore into an
 * existing instance of the target server.
 */
export function BackupsTable({ backups, showServer = false, canManage, canRestore, restoreTargets = [] }: Props) {
    const [restoring, setRestoring] = useState<BackupRow | null>(null);
    const [deleting, setDeleting] = useState<BackupRow | null>(null);
    const form = useForm({ database_instance_id: '', database: '', confirm: '', identity: '' });
    const [keyError, setKeyError] = useState<string | null>(null);
    const keys = useKeyExport(setKeyError);
    const customer = restoring !== null && needsIdentity(restoring);

    const openRestore = (backup: BackupRow) => {
        form.clearErrors();
        form.setData({
            database_instance_id: backup.instance_id ?? restoreTargets[0]?.id ?? '',
            database: backup.database_name,
            confirm: '',
            identity: '',
        });
        setRestoring(backup);
    };

    const [restoringBusy, setRestoringBusy] = useState(false);

    // Posted as JSON, not an Inertia form visit: a validation error never flashes the age identity into the session.
    const submitRestore: FormEventHandler = async (event) => {
        event.preventDefault();
        if (!restoring) return;
        setRestoringBusy(true);
        form.clearErrors();
        try {
            await requestJson(`/databases/backups/${restoring.id}/restore`, 'POST', {
                database_instance_id: form.data.database_instance_id,
                database: form.data.database,
                confirm: form.data.confirm,
                ...(customer ? { identity: form.data.identity.trim() } : {}),
            });
            setRestoring(null);
            router.reload();
        } catch (e) {
            form.setError(e instanceof HttpError && Object.keys(e.errors).length > 0 ? e.errors : { backup: errorMessage(e) });
        } finally {
            form.setData('identity', '');
            setRestoringBusy(false);
        }
    };

    if (backups.length === 0) {
        return <p className="text-muted-foreground p-6 text-sm">No backups yet.</p>;
    }

    const canPickTarget = restoreTargets.length > 0;
    const keyValue = isKeyValue(restoring?.engine);
    const targetInstances = restoreTargets.find((target) => target.id === form.data.database_instance_id)?.databases ?? [];

    return (
        <>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="pl-6">Database / instance</TableHead>
                        <TableHead>Taken</TableHead>
                        <TableHead>Size</TableHead>
                        <TableHead>Duration</TableHead>
                        <TableHead>SHA-256</TableHead>
                        <TableHead>Status</TableHead>
                        {(canManage || canRestore) && <TableHead className="w-32" />}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {backups.map((backup) => (
                        <TableRow key={backup.id}>
                            <TableCell className="pl-6">
                                <span className="font-mono">{backup.database_name}</span>
                                {showServer && <span className="text-muted-foreground"> · {backup.server_name}</span>}
                                <div className="text-muted-foreground text-xs">
                                    {backup.trigger} → {backup.storage_provider ?? 'deleted provider'}
                                </div>
                                <div className="mt-1">
                                    <BackupBadges backup={backup} />
                                </div>
                            </TableCell>
                            <TableCell className="text-sm whitespace-nowrap">{format(new Date(backup.created_at), 'yyyy-MM-dd HH:mm')}</TableCell>
                            <TableCell className="tabular-nums">{formatBytes(backup.size_bytes)}</TableCell>
                            <TableCell className="tabular-nums">{formatDuration(backup.duration_ms)}</TableCell>
                            <TableCell className="font-mono text-xs" title={backup.sha256 ?? undefined}>
                                {backup.sha256 ? `${backup.sha256.slice(0, 12)}…` : '—'}
                            </TableCell>
                            <TableCell>
                                <StatusBadge status={backup.status} title={backup.error ?? backup.prune_error} />
                                {backup.error && <p className="mt-1 max-w-xs text-xs text-red-600">{backup.error}</p>}
                                {backup.prune_error && <p className="mt-1 max-w-xs text-xs text-amber-600">Prune: {backup.prune_error}</p>}
                            </TableCell>
                            {(canManage || canRestore) && (
                                <TableCell className="text-right whitespace-nowrap">
                                    {canRestore && backup.restorable && (
                                        <Button variant="ghost" size="icon" aria-label="Download" title="Download" asChild>
                                            <a href={`/databases/backups/${backup.id}/download`}>
                                                <Download />
                                            </a>
                                        </Button>
                                    )}
                                    {canRestore && backup.restorable && (canPickTarget || backup.instance_id) && (
                                        <Button variant="ghost" size="icon" aria-label="Restore" title="Restore" onClick={() => openRestore(backup)}>
                                            <RotateCcw />
                                        </Button>
                                    )}
                                    {canRestore && backup.restorable && backup.encryption_mode === 'cp' && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label="Export backup key"
                                            title="Export backup key (for falak-restore)"
                                            onClick={() => void keys.exportKey(`/databases/backups/${backup.id}/key`)}
                                        >
                                            <KeyRound />
                                        </Button>
                                    )}
                                    {canManage && !['pending', 'running'].includes(backup.status) && (
                                        <Button variant="ghost" size="icon" aria-label="Delete backup" onClick={() => setDeleting(backup)}>
                                            <Trash2 />
                                        </Button>
                                    )}
                                </TableCell>
                            )}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>

            <Dialog open={restoring !== null} onOpenChange={(open) => !open && setRestoring(null)}>
                <DialogContent>
                    <form onSubmit={submitRestore} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Restore {restoring?.database_name}</DialogTitle>
                            <DialogDescription>
                                {keyValue ? (
                                    <>
                                        The snapshot from {restoring ? format(new Date(restoring.created_at), 'yyyy-MM-dd HH:mm') : ''} replaces all
                                        data of the target instance (restarted; its current files are kept aside on the server). A Redis 7.4+ snapshot
                                        can&apos;t go into Valkey.
                                    </>
                                ) : (
                                    <>
                                        The dump from {restoring ? format(new Date(restoring.created_at), 'yyyy-MM-dd HH:mm') : ''} is loaded into the
                                        target database. Existing data in that database is overwritten.
                                    </>
                                )}
                            </DialogDescription>
                        </DialogHeader>
                        {canPickTarget && (
                            <div className="grid gap-2">
                                <Label>Target database server</Label>
                                <Select
                                    value={form.data.database_instance_id}
                                    onValueChange={(value) => form.setData(retargetRestore(form.data, value, restoreTargets))}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {restoreTargets.map((target) => (
                                            <SelectItem key={target.id} value={target.id}>
                                                {target.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.database_instance_id} />
                            </div>
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="restore-db">Target database</Label>
                            {targetInstances.length > 0 ? (
                                <Select value={form.data.database} onValueChange={(value) => form.setData('database', value)}>
                                    <SelectTrigger id="restore-db" className="font-mono">
                                        <SelectValue placeholder="Choose a database" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {targetInstances.map((name) => (
                                            <SelectItem key={name} value={name} className="font-mono">
                                                {name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            ) : (
                                <Input
                                    id="restore-db"
                                    className="font-mono"
                                    value={form.data.database}
                                    onChange={(e) => form.setData('database', e.target.value)}
                                />
                            )}
                            <InputError message={form.errors.database} />
                        </div>
                        {customer && (
                            <div className="grid gap-2">
                                <Label htmlFor="restore-identity">Your age private key</Label>
                                <Input
                                    id="restore-identity"
                                    type="password"
                                    className="font-mono"
                                    autoComplete="off"
                                    placeholder="AGE-SECRET-KEY-1…"
                                    value={form.data.identity}
                                    onChange={(e) => form.setData('identity', e.target.value)}
                                />
                                <p className="text-muted-foreground text-xs">
                                    This backup is encrypted to your age key. It is sent to the server for this restore only and never stored.
                                </p>
                                <InputError message={(form.errors as Record<string, string | undefined>).identity} />
                            </div>
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="restore-confirm">
                                Type <span className="font-mono">{form.data.database}</span> to confirm
                            </Label>
                            <Input id="restore-confirm" value={form.data.confirm} onChange={(e) => form.setData('confirm', e.target.value)} />
                            <InputError message={form.errors.confirm ?? (form.errors as Record<string, string | undefined>).backup} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setRestoring(null)}>
                                Cancel
                            </Button>
                            <Button
                                variant="destructive"
                                disabled={
                                    restoringBusy ||
                                    form.data.confirm !== form.data.database ||
                                    !form.data.database ||
                                    (customer && !AGE_IDENTITY.test(form.data.identity.trim()))
                                }
                            >
                                Restore
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={deleting !== null} onOpenChange={(open) => !open && setDeleting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete this backup?</DialogTitle>
                        <DialogDescription>The object is deleted from the bucket and the backup can no longer be restored.</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setDeleting(null)}>
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() =>
                                deleting &&
                                router.delete(`/databases/backups/${deleting.id}`, { preserveScroll: true, onFinish: () => setDeleting(null) })
                            }
                        >
                            Delete backup
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {keyError && <p className="px-6 pb-3 text-xs text-red-600">{keyError}</p>}
            {keys.dialog}
        </>
    );
}
