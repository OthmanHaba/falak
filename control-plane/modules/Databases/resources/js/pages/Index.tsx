import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { Archive, Database, HardDrive, Plus } from 'lucide-react';
import { useState } from 'react';
import { CreateInstanceDialog } from '../components/create-instance-dialog';
import { StatusBadge, formatBytes } from '../components/database-ui';
import { type BackupRow, type CreateOptions, type DatabaseInstance } from '../types';

interface Props {
    instances: DatabaseInstance[];
    recentBackups: BackupRow[];
    storageProviders: number;
    options: CreateOptions;
    can: { manage: boolean; manageStorage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Databases', href: '/databases' }];

export default function Index({ instances, recentBackups, storageProviders, options, can }: Props) {
    const [creating, setCreating] = useState(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Databases" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Databases" description="Database containers on your servers, their databases, users and backups" />
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href="/databases/backups">
                                <Archive /> Backups
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href="/databases/storage">
                                <HardDrive /> Storage ({storageProviders})
                            </Link>
                        </Button>
                        {can.manage && options.servers.length > 0 && (
                            <Button onClick={() => setCreating(true)}>
                                <Plus /> New database
                            </Button>
                        )}
                    </div>
                </div>

                {instances.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-2 py-12 text-center">
                            <Database className="text-muted-foreground size-10" />
                            <p className="font-medium">No databases yet</p>
                            <p className="text-muted-foreground max-w-md text-sm">
                                Every database runs in its own container on one of your servers: PostgreSQL, MySQL, MariaDB, Redis or Valkey. Create
                                one here or from a project&apos;s canvas.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <Card className="py-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Engine</TableHead>
                                    <TableHead>Server</TableHead>
                                    <TableHead>Memory</TableHead>
                                    <TableHead>Databases</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {instances.map((instance) => (
                                    <TableRow key={instance.id}>
                                        <TableCell>
                                            <Link href={`/databases/instances/${instance.id}`} className="font-mono font-medium hover:underline">
                                                {instance.name}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            {instance.engine_label} {instance.version}
                                        </TableCell>
                                        <TableCell>{instance.server_name}</TableCell>
                                        <TableCell className="tabular-nums">{instance.memory_mb} MB</TableCell>
                                        <TableCell className="tabular-nums">
                                            {instance.kind === 'key_value' ? '—' : (instance.databases_count ?? 0)}
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge status={instance.status} title={instance.status_message} />
                                            {instance.status === 'active' && instance.health && instance.health !== 'healthy' && (
                                                <span className="text-muted-foreground ml-2 text-xs">{instance.health}</span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Recent backups</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {recentBackups.length === 0 ? (
                            <p className="text-muted-foreground text-sm">No backups yet.</p>
                        ) : (
                            <ul className="divide-y text-sm">
                                {recentBackups.map((backup) => (
                                    <li key={backup.id} className="flex items-center justify-between gap-4 py-2">
                                        <span>
                                            <span className="font-medium">{backup.database_name}</span>
                                            <span className="text-muted-foreground"> on {backup.instance_name ?? backup.server_name}</span>
                                        </span>
                                        <span className="flex items-center gap-3">
                                            <span className="text-muted-foreground tabular-nums">{formatBytes(backup.size_bytes)}</span>
                                            <span className="text-muted-foreground">
                                                {formatDistanceToNow(new Date(backup.created_at), { addSuffix: true })}
                                            </span>
                                            <StatusBadge status={backup.status} title={backup.error} />
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>

            {can.manage && <CreateInstanceDialog open={creating} onOpenChange={setCreating} options={options} />}
        </AppLayout>
    );
}
