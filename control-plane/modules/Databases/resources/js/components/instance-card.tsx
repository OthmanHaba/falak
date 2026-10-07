import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { router, useForm } from '@inertiajs/react';
import { format } from 'date-fns';
import { ArrowUpCircle, KeyRound, RotateCw, Settings2, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { type DatabaseInstance, type InstanceOptions } from '../types';
import { StatusBadge } from './database-ui';

interface Props {
    instance: DatabaseInstance;
    options: InstanceOptions;
    canManage: boolean;
}

const PERSISTENCE: Record<string, string> = {
    rdb: 'Snapshots (RDB)',
    aof: 'Append-only file (AOF)',
    none: 'None (memory only)',
};

type Open = 'limits' | 'upgrade' | 'password' | 'delete' | null;

/** The container itself: status and health, limits and settings, restart, upgrade, password, delete. */
export function InstanceCard({ instance, options, canManage }: Props) {
    const [open, setOpen] = useState<Open>(null);
    const keyValue = instance.kind === 'key_value';
    const running = instance.status === 'active';

    const limits = useForm({
        memory_mb: String(instance.memory_mb),
        cpus: instance.cpus === null ? '' : String(instance.cpus),
        max_connections: instance.settings.max_connections ? String(instance.settings.max_connections) : '',
        slow_query_ms: instance.settings.slow_query_ms !== undefined ? String(instance.settings.slow_query_ms) : '',
        eviction: instance.settings.eviction ?? 'noeviction',
        persistence: instance.settings.persistence ?? 'rdb',
        public_access: instance.public_access,
        require_tls: instance.require_tls,
    });
    const upgrade = useForm({ version: options.versions[options.versions.length - 1] ?? instance.version });
    const password = useForm({ password: '' });
    const destroy = useForm({ confirm: '', delete_volume: false });
    const close = () => setOpen(null);

    const submitLimits: FormEventHandler = (event) => {
        event.preventDefault();
        limits.transform((data) => ({
            memory_mb: data.memory_mb === '' ? null : Number(data.memory_mb),
            cpus: data.cpus === '' ? null : Number(data.cpus),
            settings: keyValue
                ? {
                      eviction: data.eviction,
                      persistence: data.persistence,
                      slow_query_ms: data.slow_query_ms === '' ? null : Number(data.slow_query_ms),
                  }
                : {
                      max_connections: data.max_connections === '' ? null : Number(data.max_connections),
                      slow_query_ms: data.slow_query_ms === '' ? null : Number(data.slow_query_ms),
                  },
            public_access: data.public_access,
            require_tls: data.require_tls || data.public_access,
        }));
        limits.put(`/databases/instances/${instance.id}`, { preserveScroll: true, onSuccess: close });
    };

    const submitUpgrade: FormEventHandler = (event) => {
        event.preventDefault();
        upgrade.post(`/databases/instances/${instance.id}/upgrade`, { preserveScroll: true, onSuccess: close });
    };

    const submitPassword: FormEventHandler = (event) => {
        event.preventDefault();
        password.transform((data) => ({ password: data.password || null }));
        password.post(`/databases/instances/${instance.id}/password`, {
            preserveScroll: true,
            onSuccess: () => {
                password.reset();
                close();
            },
        });
    };

    const submitDelete: FormEventHandler = (event) => {
        event.preventDefault();
        destroy.delete(`/databases/instances/${instance.id}`);
    };

    const major = upgrade.data.version !== instance.version;

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between gap-2">
                <CardTitle className="text-base">Container</CardTitle>
                <StatusBadge status={instance.status} title={instance.status_message} />
            </CardHeader>
            <CardContent className="space-y-4 text-sm">
                {instance.status_message && <p className="text-muted-foreground text-xs">{instance.status_message}</p>}
                <dl className="grid grid-cols-2 gap-x-4 gap-y-2">
                    <dt className="text-muted-foreground">Health</dt>
                    <dd>{instance.health ?? 'not reported yet'}</dd>
                    <dt className="text-muted-foreground">Image</dt>
                    <dd className="truncate font-mono text-xs" title={instance.image_digest ?? undefined}>
                        {instance.image}
                    </dd>
                    <dt className="text-muted-foreground">Memory</dt>
                    <dd className="tabular-nums">
                        {instance.memory_mb} MB{instance.cpus ? ` · ${instance.cpus} CPU` : ''}
                    </dd>
                    {keyValue && (
                        <>
                            <dt className="text-muted-foreground">Eviction</dt>
                            <dd className="font-mono text-xs">{instance.settings.eviction ?? 'noeviction'}</dd>
                            <dt className="text-muted-foreground">Persistence</dt>
                            <dd>{PERSISTENCE[instance.settings.persistence ?? 'rdb']}</dd>
                        </>
                    )}
                    <dt className="text-muted-foreground">TLS</dt>
                    <dd>
                        {instance.require_tls ? 'required' : 'available'}
                        {instance.tls_expires_at && (
                            <span className="text-muted-foreground text-xs"> · until {format(new Date(instance.tls_expires_at), 'yyyy-MM-dd')}</span>
                        )}
                    </dd>
                    <dt className="text-muted-foreground">Public access</dt>
                    <dd>{instance.public_access ? 'on' : 'off'}</dd>
                    {instance.retire_at && (
                        <>
                            <dt className="text-muted-foreground">Removed</dt>
                            <dd>{format(new Date(instance.retire_at), 'yyyy-MM-dd HH:mm')}</dd>
                        </>
                    )}
                </dl>
                {canManage && (
                    <div className="flex flex-wrap gap-2">
                        <Button size="sm" variant="outline" disabled={!running} onClick={() => setOpen('limits')}>
                            <Settings2 /> Limits &amp; settings
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={!running}
                            onClick={() => router.post(`/databases/instances/${instance.id}/restart`, {}, { preserveScroll: true })}
                        >
                            <RotateCw /> Restart
                        </Button>
                        <Button size="sm" variant="outline" disabled={!options.upgradable} onClick={() => setOpen('upgrade')}>
                            <ArrowUpCircle /> Upgrade
                        </Button>
                        <Button size="sm" variant="outline" disabled={!running || instance.rotating_password} onClick={() => setOpen('password')}>
                            <KeyRound /> {instance.rotating_password ? 'Rotating…' : keyValue ? 'Rotate password' : 'Rotate superuser password'}
                        </Button>
                        <Button size="sm" variant="outline" disabled={instance.status === 'upgrading'} onClick={() => setOpen('delete')}>
                            <Trash2 /> Delete
                        </Button>
                    </div>
                )}
            </CardContent>

            <Dialog open={open === 'limits'} onOpenChange={(value) => !value && close()}>
                <DialogContent>
                    <form onSubmit={submitLimits} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Limits &amp; settings</DialogTitle>
                            <DialogDescription>
                                The engine&apos;s config is tuned to the memory limit. Saving recreates the container with the new settings (a short
                                restart); the data stays on its volume.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="limit-memory">Memory (MB)</Label>
                                <Input
                                    id="limit-memory"
                                    inputMode="numeric"
                                    value={limits.data.memory_mb}
                                    onChange={(e) => limits.setData('memory_mb', e.target.value.replace(/\D/g, ''))}
                                />
                                <p className="text-muted-foreground text-xs">At least {options.min_memory_mb} MB.</p>
                                <InputError message={limits.errors.memory_mb} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="limit-cpus">CPUs</Label>
                                <Input
                                    id="limit-cpus"
                                    inputMode="decimal"
                                    value={limits.data.cpus}
                                    placeholder="No limit"
                                    onChange={(e) => limits.setData('cpus', e.target.value.replace(/[^\d.]/g, ''))}
                                />
                                <InputError message={limits.errors.cpus} />
                            </div>
                            {keyValue ? (
                                <>
                                    <div className="grid gap-2">
                                        <Label>Eviction</Label>
                                        <Select value={limits.data.eviction} onValueChange={(value) => limits.setData('eviction', value)}>
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {options.evictions.map((item) => (
                                                    <SelectItem key={item} value={item}>
                                                        {item}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="grid gap-2">
                                        <Label>Persistence</Label>
                                        <Select
                                            value={limits.data.persistence}
                                            onValueChange={(value) => limits.setData('persistence', value as 'rdb' | 'aof' | 'none')}
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {options.persistences.map((item) => (
                                                    <SelectItem key={item} value={item}>
                                                        {PERSISTENCE[item] ?? item}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </>
                            ) : (
                                <div className="grid gap-2">
                                    <Label htmlFor="limit-connections">Max connections</Label>
                                    <Input
                                        id="limit-connections"
                                        inputMode="numeric"
                                        value={limits.data.max_connections}
                                        placeholder="Default"
                                        onChange={(e) => limits.setData('max_connections', e.target.value.replace(/\D/g, ''))}
                                    />
                                </div>
                            )}
                            <div className="grid gap-2">
                                <Label htmlFor="limit-slow">Slow query log (ms)</Label>
                                <Input
                                    id="limit-slow"
                                    inputMode="numeric"
                                    value={limits.data.slow_query_ms}
                                    placeholder="Default"
                                    onChange={(e) => limits.setData('slow_query_ms', e.target.value.replace(/\D/g, ''))}
                                />
                            </div>
                        </div>
                        {keyValue && limits.data.persistence === 'none' && (
                            <p role="alert" className="rounded-md bg-amber-500/15 px-3 py-2 text-xs text-amber-700 dark:text-amber-300">
                                Nothing is written to disk: every restart starts the instance empty.
                            </p>
                        )}
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={limits.data.require_tls} onCheckedChange={(value) => limits.setData('require_tls', value === true)} />
                            Require TLS for every connection
                        </label>
                        <label className="flex items-start gap-2 text-sm">
                            <Checkbox
                                checked={limits.data.public_access}
                                onCheckedChange={(value) => limits.setData('public_access', value === true)}
                                className="mt-0.5"
                            />
                            <span>
                                Public access
                                <span className="text-muted-foreground block text-xs">
                                    Publishes the port on every address of the server, TLS required. Restrict who reaches it in the firewall.
                                </span>
                            </span>
                        </label>
                        {Object.entries(limits.errors)
                            .filter(([key]) => key.startsWith('settings') || key === 'instance')
                            .map(([key, message]) => (
                                <InputError key={key} message={message} />
                            ))}
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={close}>
                                Cancel
                            </Button>
                            <Button disabled={limits.processing}>Save and apply</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={open === 'upgrade'} onOpenChange={(value) => !value && close()}>
                <DialogContent>
                    <form onSubmit={submitUpgrade} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Upgrade {instance.name}</DialogTitle>
                            <DialogDescription>
                                {major && !keyValue
                                    ? `A new ${instance.engine_label} ${upgrade.data.version} container is created and every database is copied into it; then it takes over the name apps use and the old container is stopped and kept for 24 hours.`
                                    : 'The latest image of this version is pulled and the container is recreated on the same volume (a short restart).'}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label>Version</Label>
                            <Select value={upgrade.data.version} onValueChange={(value) => upgrade.setData('version', value)}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.versions.map((version) => (
                                        <SelectItem key={version} value={version}>
                                            {instance.engine_label} {version}
                                            {version === instance.version ? ' (current, latest image)' : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={upgrade.errors.version} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={close}>
                                Cancel
                            </Button>
                            <Button disabled={upgrade.processing}>Upgrade</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={open === 'password'} onOpenChange={(value) => !value && close()}>
                <DialogContent>
                    <form onSubmit={submitPassword} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Rotate {keyValue ? 'the password' : 'the superuser password'}</DialogTitle>
                            <DialogDescription>
                                {keyValue
                                    ? 'The running instance takes the new password right away. Sites get it through their references on their next deploy.'
                                    : 'Only Falak uses the superuser; application users keep their passwords.'}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label htmlFor="instance-password">New password</Label>
                            <Input
                                id="instance-password"
                                type="password"
                                autoComplete="new-password"
                                value={password.data.password}
                                placeholder="Generated when empty"
                                onChange={(e) => password.setData('password', e.target.value)}
                            />
                            <InputError message={password.errors.password} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={close}>
                                Cancel
                            </Button>
                            <Button disabled={password.processing}>Rotate</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={open === 'delete'} onOpenChange={(value) => !value && close()}>
                <DialogContent>
                    <form onSubmit={submitDelete} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Delete {instance.name}?</DialogTitle>
                            <DialogDescription>
                                The container and its databases go. Its data stays in an unattached volume (Volumes) unless you delete it too. Backups
                                in storage are kept.
                            </DialogDescription>
                        </DialogHeader>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={destroy.data.delete_volume}
                                onCheckedChange={(value) => destroy.setData('delete_volume', value === true)}
                            />
                            Also delete the data volume
                        </label>
                        <div className="grid gap-2">
                            <Label htmlFor="instance-confirm">
                                Type <span className="font-mono">{instance.name}</span> to confirm
                            </Label>
                            <Input id="instance-confirm" value={destroy.data.confirm} onChange={(e) => destroy.setData('confirm', e.target.value)} />
                            <InputError message={destroy.errors.confirm ?? (destroy.errors as Record<string, string | undefined>).instance} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={close}>
                                Cancel
                            </Button>
                            <Button variant="destructive" disabled={destroy.processing || destroy.data.confirm !== instance.name}>
                                Delete database
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
