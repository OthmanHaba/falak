import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Plus, Server as ServerIcon } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { AgentDot, CopyButton, ServerStatusBadge, UsageBar } from '../components/server-ui';
import { type ServerStatus, type ServerSummary } from '../types';

interface Props {
    servers: ServerSummary[];
    filters: { search?: string; type?: string; status?: string };
    types: { value: string; label: string }[];
    can: { create: boolean };
}

const ALL = 'all';
const STATUSES: ServerStatus[] = ['creating', 'provisioning', 'active', 'error', 'deleting'];
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Servers', href: '/servers' }];

export default function Index({ servers, filters, types, can }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');

    const apply = (next: Partial<Props['filters']>) => {
        const query = { ...filters, search, ...next };
        const cleaned = Object.fromEntries(Object.entries(query).filter(([, value]) => value && value !== ALL));
        router.get(route('servers.index'), cleaned, { preserveState: true, replace: true });
    };

    const submitSearch: FormEventHandler = (event) => {
        event.preventDefault();
        apply({ search });
    };

    const filtered = Boolean(filters.search || filters.type || filters.status);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Servers" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Servers" description="Machines managed by the Kiln agent" />
                    {can.create && (
                        <Button asChild>
                            <Link href={route('servers.create')}>
                                <Plus /> Create server
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <form onSubmit={submitSearch} className="flex-1 sm:max-w-xs">
                        <Input
                            placeholder="Search name or IP…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            aria-label="Search servers"
                        />
                    </form>
                    <Select value={filters.type ?? ALL} onValueChange={(value) => apply({ type: value })}>
                        <SelectTrigger className="w-44" aria-label="Filter by type">
                            <SelectValue placeholder="All types" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All types</SelectItem>
                            {types.map((type) => (
                                <SelectItem key={type.value} value={type.value}>
                                    {type.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select value={filters.status ?? ALL} onValueChange={(value) => apply({ status: value })}>
                        <SelectTrigger className="w-40" aria-label="Filter by status">
                            <SelectValue placeholder="All statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All statuses</SelectItem>
                            {STATUSES.map((status) => (
                                <SelectItem key={status} value={status} className="capitalize">
                                    {status}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {servers.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-3 py-12 text-center">
                            <ServerIcon className="text-muted-foreground size-10" />
                            <div>
                                <p className="font-medium">{filtered ? 'No servers match these filters' : 'No servers yet'}</p>
                                <p className="text-muted-foreground text-sm">
                                    {filtered
                                        ? 'Try a different search or filter.'
                                        : 'Create a server at a cloud provider or bring your own machine.'}
                                </p>
                            </div>
                            {can.create && !filtered && (
                                <Button asChild>
                                    <Link href={route('servers.create')}>
                                        <Plus /> Create server
                                    </Link>
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                ) : (
                    <Card className="py-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Agent</TableHead>
                                    <TableHead>Provider</TableHead>
                                    <TableHead>IP address</TableHead>
                                    <TableHead>PHP</TableHead>
                                    <TableHead>Load</TableHead>
                                    <TableHead>Memory</TableHead>
                                    <TableHead>Disk</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {servers.map((server) => (
                                    <TableRow key={server.id}>
                                        <TableCell>
                                            <Link href={route('servers.show', server.id)} className="font-medium hover:underline">
                                                {server.name}
                                            </Link>
                                            <div className="text-muted-foreground text-xs">{server.type_label}</div>
                                        </TableCell>
                                        <TableCell>
                                            <ServerStatusBadge status={server.status} />
                                        </TableCell>
                                        <TableCell>
                                            <AgentDot status={server.agent?.status} />
                                        </TableCell>
                                        <TableCell>
                                            <div className="text-sm">{server.provider_label}</div>
                                            {server.region && <div className="text-muted-foreground text-xs">{server.region}</div>}
                                        </TableCell>
                                        <TableCell>
                                            {server.ipv4 ? (
                                                <span className="inline-flex items-center gap-1 font-mono text-xs">
                                                    {server.ipv4}
                                                    <CopyButton value={server.ipv4} label="Copy IP address" />
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground text-xs">—</span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-sm">{server.php ?? '—'}</TableCell>
                                        <TableCell className="text-sm tabular-nums">
                                            {server.load1 !== null ? server.load1.toFixed(2) : '—'}
                                        </TableCell>
                                        <TableCell>
                                            <UsageBar value={server.memory_percent} label="Memory" />
                                        </TableCell>
                                        <TableCell>
                                            <UsageBar value={server.disk_percent} label="Disk" />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
