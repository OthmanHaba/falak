import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { format } from 'date-fns';
import { Settings2 } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';
import { BackupsTable } from '../components/backups-table';
import { ConnectionCard } from '../components/connection-card';
import { StatusBadge, formatBytes, formatDuration } from '../components/database-ui';
import { DatabasesCard } from '../components/databases-card';
import { SchedulesCard } from '../components/schedules-card';
import { UsersCard } from '../components/users-card';
import {
    type BackupRow,
    type Connection,
    type DatabaseRow,
    type DatabaseServer,
    type DatabaseUserRow,
    type RestoreRow,
    type ScheduleRow,
    type StorageOption,
} from '../types';

interface Props {
    server: DatabaseServer;
    connection: Connection;
    databases: DatabaseRow[];
    users: DatabaseUserRow[];
    schedules: ScheduleRow[];
    backups: BackupRow[];
    restores: RestoreRow[];
    storageProviders: StorageOption[];
    restoreTargets: { id: string; label: string }[];
    options: { privileges: string[]; versions: string[]; compressions: string[]; default_charset: string | null; default_collation: string | null };
    can: { manage: boolean; reveal: boolean; restore: boolean; manageStorage: boolean };
}

const CONVERGING = ['pending', 'running', 'deleting'];

export default function Show({
    server,
    connection,
    databases,
    users,
    schedules,
    backups,
    restores,
    storageProviders,
    restoreTargets,
    options,
    can,
}: Props) {
    const [editingEngine, setEditingEngine] = useState(false);
    const engine = useForm({ version: server.version_source === 'manual' ? (server.version ?? '') : '', port: String(server.port) });
    // Redis / Valkey: instances with their own port and `default` user; no extra users, schedules or backups yet.
    const keyValue = server.kind === 'key_value';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Databases', href: '/databases' },
        { title: server.server_name, href: `/databases/servers/${server.id}` },
    ];

    const converging =
        databases.some((d) => CONVERGING.includes(d.status)) ||
        users.some((u) => CONVERGING.includes(u.status)) ||
        backups.some((b) => CONVERGING.includes(b.status)) ||
        restores.some((r) => CONVERGING.includes(r.status));

    // Agent results arrive asynchronously: refresh while anything is converging.
    useEffect(() => {
        if (!converging) return;
        const timer = window.setInterval(() => router.reload({ only: ['databases', 'users', 'backups', 'restores'] }), 3000);

        return () => window.clearInterval(timer);
    }, [converging]);

    const submitEngine: FormEventHandler = (event) => {
        event.preventDefault();
        engine.transform((data) => ({
            version: data.version === '' || data.version === 'auto' ? null : data.version,
            port: keyValue ? null : Number(data.port),
        }));
        engine.put(`/databases/servers/${server.id}`, { preserveScroll: true, onSuccess: () => setEditingEngine(false) });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${server.server_name} databases`} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 className="text-xl font-semibold tracking-tight">{server.server_name}</h2>
                        <p className="text-muted-foreground text-sm">
                            {server.engine_label} {server.version ?? ''}
                            {keyValue ? ' · one process per instance (ports 6380–6479)' : ` · port ${server.port}`}
                            {server.dedicated ? (keyValue ? ' · dedicated cache server' : ' · dedicated database server') : ''}
                            {server.version_source === 'default' && ' · version assumed from the distro'}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href={`/servers/${server.server_id}`}>Server</Link>
                        </Button>
                        {can.manage && (
                            <Button variant="outline" onClick={() => setEditingEngine(true)}>
                                <Settings2 /> Engine
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid gap-6 xl:grid-cols-3">
                    <div className="space-y-6 xl:col-span-2">
                        <DatabasesCard
                            server={server}
                            databases={databases}
                            storageProviders={storageProviders}
                            canManage={can.manage}
                            defaults={{ charset: options.default_charset, collation: options.default_collation }}
                        />
                        {!keyValue && (
                            <UsersCard server={server} users={users} databases={databases} privileges={options.privileges} canManage={can.manage} />
                        )}
                    </div>
                    <div className="space-y-6">
                        {keyValue ? (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Connect</CardTitle>
                                </CardHeader>
                                <CardContent className="text-muted-foreground space-y-2 text-sm">
                                    <p>
                                        Each instance listens on 127.0.0.1 and its own port, with the password of its <code>default</code> user. Sites
                                        on {server.server_name} reference it as <code>{'${{ <service>.REDIS_URL }}'}</code>; the instance&apos;s panel
                                        on the canvas shows the connection details.
                                    </p>
                                    <p>Backups, containers and other servers come in a later release.</p>
                                </CardContent>
                            </Card>
                        ) : (
                            <ConnectionCard connection={connection} databases={databases} users={users} canReveal={can.reveal} />
                        )}
                    </div>
                </div>

                {!keyValue && (
                    <SchedulesCard
                        server={server}
                        schedules={schedules}
                        databases={databases}
                        storageProviders={storageProviders}
                        canManage={can.manage}
                    />
                )}

                {!keyValue && (
                    <Card className="gap-0 py-0">
                        <CardHeader className="border-b py-4">
                            <CardTitle className="text-base">Backups</CardTitle>
                        </CardHeader>
                        <CardContent className="px-0">
                            <BackupsTable backups={backups} canManage={can.manage} canRestore={can.restore} restoreTargets={restoreTargets} />
                        </CardContent>
                    </Card>
                )}

                {restores.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Restores</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="divide-y text-sm">
                                {restores.map((restore) => (
                                    <li key={restore.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                        <span>
                                            <span className="font-mono">{restore.source_database}</span> →{' '}
                                            <span className="font-mono">{restore.database_name}</span>
                                            <span className="text-muted-foreground">
                                                {' '}
                                                · {format(new Date(restore.created_at), 'yyyy-MM-dd HH:mm')}
                                            </span>
                                        </span>
                                        <span className="flex items-center gap-3">
                                            <span className="text-muted-foreground tabular-nums">
                                                {formatBytes(restore.bytes)} · {formatDuration(restore.duration_ms)}
                                            </span>
                                            <StatusBadge status={restore.status} title={restore.error} />
                                        </span>
                                        {restore.error && <p className="w-full text-xs text-red-600">{restore.error}</p>}
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}
            </div>

            <Dialog open={editingEngine} onOpenChange={setEditingEngine}>
                <DialogContent>
                    <form onSubmit={submitEngine} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Engine settings</DialogTitle>
                            <DialogDescription>
                                The version is detected from the agent; pin it here only when detection is wrong. The port is used in connection
                                details.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label>Version</Label>
                            <Select value={engine.data.version || 'auto'} onValueChange={(value) => engine.setData('version', value)}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="auto">Detect automatically</SelectItem>
                                    {options.versions.map((version) => (
                                        <SelectItem key={version} value={version}>
                                            {server.engine_label} {version}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={engine.errors.version} />
                        </div>
                        {!keyValue && (
                            <div className="grid gap-2">
                                <Label htmlFor="engine-port">Port</Label>
                                <Input
                                    id="engine-port"
                                    type="number"
                                    value={engine.data.port}
                                    onChange={(e) => engine.setData('port', e.target.value)}
                                />
                                <InputError message={engine.errors.port} />
                            </div>
                        )}
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setEditingEngine(false)}>
                                Cancel
                            </Button>
                            <Button disabled={engine.processing}>Save</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
