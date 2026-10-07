import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { format } from 'date-fns';
import { useEffect } from 'react';
import { BackupsTable } from '../components/backups-table';
import { ConnectionCard } from '../components/connection-card';
import { StatusBadge, formatBytes, formatDuration } from '../components/database-ui';
import { DatabasesCard } from '../components/databases-card';
import { InstanceCard } from '../components/instance-card';
import { SchedulesCard } from '../components/schedules-card';
import { UsersCard } from '../components/users-card';
import {
    instanceSummary,
    type BackupRow,
    type Connection,
    type DatabaseInstance,
    type DatabaseRow,
    type DatabaseUserRow,
    type InstanceOptions,
    type RestoreRow,
    type RestoreTarget,
    type ScheduleRow,
    type StorageOption,
} from '../types';

interface Props {
    instance: DatabaseInstance;
    connection: Connection;
    databases: DatabaseRow[];
    users: DatabaseUserRow[];
    schedules: ScheduleRow[];
    backups: BackupRow[];
    restores: RestoreRow[];
    storageProviders: StorageOption[];
    restoreTargets: RestoreTarget[];
    options: InstanceOptions;
    can: { manage: boolean; reveal: boolean; restore: boolean; manageStorage: boolean };
}

const CONVERGING = ['pending', 'running', 'deleting', 'upgrading'];

export default function Show({
    instance,
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
    // Redis / Valkey: one keyspace and its `default` user; no extra databases or users (backups: RDB snapshots).
    const keyValue = instance.kind === 'key_value';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Databases', href: '/databases' },
        { title: instance.name, href: `/databases/instances/${instance.id}` },
    ];

    const converging =
        CONVERGING.includes(instance.status) ||
        instance.rotating_password ||
        databases.some((d) => CONVERGING.includes(d.status)) ||
        users.some((u) => CONVERGING.includes(u.status)) ||
        backups.some((b) => CONVERGING.includes(b.status)) ||
        restores.some((r) => CONVERGING.includes(r.status));

    // Agent results arrive asynchronously: refresh while anything is converging.
    useEffect(() => {
        if (!converging) return;
        const timer = window.setInterval(
            () => router.reload({ only: ['instance', 'connection', 'databases', 'users', 'backups', 'restores'] }),
            3000,
        );

        return () => window.clearInterval(timer);
    }, [converging]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${instance.name} · databases`} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 className="flex items-center gap-2 font-mono text-xl font-semibold tracking-tight">
                            {instance.name}
                            <StatusBadge status={instance.status} title={instance.status_message} />
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            {instanceSummary(instance)} · on {instance.server_name}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        {instance.volume_id && (
                            <Button variant="outline" asChild>
                                <Link href={`/volumes/${instance.volume_id}`}>Data volume</Link>
                            </Button>
                        )}
                        <Button variant="outline" asChild>
                            <Link href={`/servers/${instance.server_id}`}>Server</Link>
                        </Button>
                    </div>
                </div>

                <div className="grid gap-6 xl:grid-cols-3">
                    <div className="space-y-6 xl:col-span-2">
                        <DatabasesCard
                            instance={instance}
                            databases={databases}
                            storageProviders={storageProviders}
                            canManage={can.manage}
                            defaults={{ charset: options.default_charset ?? null, collation: options.default_collation ?? null }}
                        />
                        {!keyValue && (
                            <UsersCard
                                instance={instance}
                                users={users}
                                databases={databases}
                                privileges={options.privileges}
                                canManage={can.manage}
                            />
                        )}
                    </div>
                    <div className="space-y-6">
                        <InstanceCard instance={instance} options={options} canManage={can.manage} />
                        <ConnectionCard connection={connection} databases={databases} users={users} canReveal={can.reveal} />
                    </div>
                </div>

                <SchedulesCard
                    instance={instance}
                    schedules={schedules}
                    databases={databases}
                    storageProviders={storageProviders}
                    canManage={can.manage}
                    drillServers={options.drill_servers}
                />

                <Card className="gap-0 py-0">
                    <CardHeader className="border-b py-4">
                        <CardTitle className="text-base">Backups</CardTitle>
                    </CardHeader>
                    <CardContent className="px-0">
                        <BackupsTable backups={backups} canManage={can.manage} canRestore={can.restore} restoreTargets={restoreTargets} />
                    </CardContent>
                </Card>

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
                                        {(restore.warnings ?? []).map((warning) => (
                                            <p key={warning} className="text-warning w-full text-xs">
                                                {warning}
                                            </p>
                                        ))}
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
