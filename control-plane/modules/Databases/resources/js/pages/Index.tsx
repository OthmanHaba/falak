import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { Archive, Database, HardDrive } from 'lucide-react';
import { StatusBadge, formatBytes } from '../components/database-ui';
import { type BackupRow, type DatabaseServer } from '../types';

interface Props {
    servers: DatabaseServer[];
    recentBackups: BackupRow[];
    storageProviders: number;
    can: { manageStorage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Databases', href: '/databases' }];

export default function Index({ servers, recentBackups, storageProviders }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Databases" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Databases" description="Database engines on your servers, their databases, users and backups" />
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
                    </div>
                </div>

                {servers.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-2 py-12 text-center">
                            <Database className="text-muted-foreground size-10" />
                            <p className="font-medium">No database servers yet</p>
                            <p className="text-muted-foreground max-w-md text-sm">
                                Create an app server with a database engine, or a dedicated database server. Engines appear here once provisioning
                                finishes.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <Card className="py-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Server</TableHead>
                                    <TableHead>Engine</TableHead>
                                    <TableHead>Port</TableHead>
                                    <TableHead>Databases</TableHead>
                                    <TableHead>Users</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {servers.map((server) => (
                                    <TableRow key={server.id}>
                                        <TableCell>
                                            <Link href={`/databases/servers/${server.id}`} className="font-medium hover:underline">
                                                {server.server_name}
                                            </Link>
                                            {server.dedicated && <span className="text-muted-foreground ml-2 text-xs">dedicated</span>}
                                        </TableCell>
                                        <TableCell>
                                            {server.engine_label} {server.version ?? ''}
                                            {server.version_source === 'default' && (
                                                <span
                                                    className="text-muted-foreground ml-1 text-xs"
                                                    title="Not reported by the agent; distro default assumed"
                                                >
                                                    (assumed)
                                                </span>
                                            )}
                                        </TableCell>
                                        {server.kind === 'key_value' ? (
                                            <>
                                                {/* Instances have their own ports; the stock one on 6379 is not Falak's. */}
                                                <TableCell className="tabular-nums">{server.instance_ports?.join(', ') || '—'}</TableCell>
                                                <TableCell className="tabular-nums">
                                                    {server.databases_count ?? 0} instance{server.databases_count === 1 ? '' : 's'}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground">—</TableCell>
                                            </>
                                        ) : (
                                            <>
                                                <TableCell className="tabular-nums">{server.port}</TableCell>
                                                <TableCell className="tabular-nums">{server.databases_count ?? 0}</TableCell>
                                                <TableCell className="tabular-nums">{server.users_count ?? 0}</TableCell>
                                            </>
                                        )}
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
                                            <span className="text-muted-foreground"> on {backup.server_name}</span>
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
        </AppLayout>
    );
}
