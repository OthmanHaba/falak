import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Network, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';
import { ApplyStatusBadge } from '../components/network-ui';
import { type ApplyStatus } from '../types';

interface Member {
    id: string;
    server_id: string;
    server_name: string;
    public_ipv4: string | null;
    address: string;
    public_key: string;
    key_status: 'pending' | 'installed' | 'failed';
    status: ApplyStatus;
    error: string | null;
    command_id: string | null;
    applied_at: string | null;
}

interface Props {
    network: { id: string; name: string; cidr: string; interface: string; listen_port: number; created_at: string };
    members: Member[];
    availableServers: { id: string; name: string; ipv4: string | null; type_label: string }[];
    can: { manage: boolean };
}

export default function PrivateNetwork({ network, members, availableServers, can }: Props) {
    const [adding, setAdding] = useState(false);
    const [removing, setRemoving] = useState<Member | null>(null);
    const [deleting, setDeleting] = useState(false);
    const addForm = useForm({ server_id: '' });
    const deleteForm = useForm({ name: '' });
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Network', href: '/network' },
        { title: network.name, href: `/network/private-networks/${network.id}` },
    ];

    const converging = members.some((member) => member.status === 'applying' || (member.key_status === 'pending' && member.status !== 'failed'));

    // Refresh while any member is still converging (key delivery / wireguard apply in flight).
    useEffect(() => {
        if (!converging) {
            return;
        }

        const timer = window.setInterval(() => router.reload({ only: ['members'] }), 3000);

        return () => window.clearInterval(timer);
    }, [converging]);

    const add: FormEventHandler = (event) => {
        event.preventDefault();
        addForm.post(`/network/private-networks/${network.id}/members`, {
            preserveScroll: true,
            onSuccess: () => {
                addForm.reset();
                setAdding(false);
            },
        });
    };

    const remove = () => {
        if (!removing) return;
        router.delete(`/network/private-networks/${network.id}/members/${removing.id}`, { preserveScroll: true, onFinish: () => setRemoving(null) });
    };

    const destroy: FormEventHandler = (event) => {
        event.preventDefault();
        deleteForm.delete(`/network/private-networks/${network.id}`);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={network.name} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title={network.name} description={`${network.cidr} · interface ${network.interface} · UDP ${network.listen_port}`} />
                    {can.manage && (
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                onClick={() => router.post(`/network/private-networks/${network.id}/apply`, {}, { preserveScroll: true })}
                            >
                                <RefreshCw /> Re-apply
                            </Button>
                            <Button onClick={() => setAdding(true)} disabled={availableServers.length === 0}>
                                <Plus /> Add server
                            </Button>
                        </div>
                    )}
                </div>

                {members.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-2 py-12 text-center">
                            <Network className="text-muted-foreground size-10" />
                            <p className="font-medium">No servers in this network</p>
                            <p className="text-muted-foreground text-sm">
                                Each server you add gets a private address and a tunnel to every other member.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <Card className="py-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Server</TableHead>
                                    <TableHead>Private address</TableHead>
                                    <TableHead>Endpoint</TableHead>
                                    <TableHead>Public key</TableHead>
                                    <TableHead>Status</TableHead>
                                    {can.manage && <TableHead className="w-12" />}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {members.map((member) => (
                                    <TableRow key={member.id}>
                                        <TableCell className="font-medium">
                                            <Link href={`/servers/${member.server_id}`} className="hover:underline">
                                                {member.server_name}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">{member.address}</TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {member.public_ipv4 ? `${member.public_ipv4}:${network.listen_port}` : '—'}
                                        </TableCell>
                                        <TableCell className="max-w-48 truncate font-mono text-xs" title={member.public_key}>
                                            {member.public_key}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-col items-start gap-1">
                                                <ApplyStatusBadge status={member.status} />
                                                {member.key_status !== 'installed' && (
                                                    <span className="text-muted-foreground text-xs">key {member.key_status}</span>
                                                )}
                                                {member.error && <span className="text-xs text-red-600 dark:text-red-400">{member.error}</span>}
                                            </div>
                                        </TableCell>
                                        {can.manage && (
                                            <TableCell>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() => setRemoving(member)}
                                                    aria-label={`Remove ${member.server_name}`}
                                                >
                                                    <Trash2 />
                                                </Button>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                )}

                <p className="text-muted-foreground text-xs">
                    Private keys are generated for each server, installed on the host and then discarded by Kiln. Firewalls of members accept
                    WireGuard traffic from peers automatically.
                </p>

                {can.manage && (
                    <Card className="border-red-500/30">
                        <CardContent className="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <p className="font-medium">Delete network</p>
                                <p className="text-muted-foreground text-sm">Tears down the WireGuard interface on every member.</p>
                            </div>
                            <Button variant="destructive" onClick={() => setDeleting(true)}>
                                Delete network
                            </Button>
                        </CardContent>
                    </Card>
                )}
            </div>

            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent>
                    <form onSubmit={add} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Add server</DialogTitle>
                            <DialogDescription>Only active servers can join.</DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label>Server</Label>
                            <Select value={addForm.data.server_id} onValueChange={(value) => addForm.setData('server_id', value)}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Choose a server" />
                                </SelectTrigger>
                                <SelectContent>
                                    {availableServers.map((server) => (
                                        <SelectItem key={server.id} value={server.id}>
                                            {server.name} · {server.type_label}
                                            {server.ipv4 ? ` · ${server.ipv4}` : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={addForm.errors.server_id} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setAdding(false)}>
                                Cancel
                            </Button>
                            <Button disabled={addForm.processing || addForm.data.server_id === ''}>Add server</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={removing !== null} onOpenChange={(value) => !value && setRemoving(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Remove {removing?.server_name}?</DialogTitle>
                        <DialogDescription>Its WireGuard interface is removed and the other members stop peering with it.</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setRemoving(null)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={remove}>
                            Remove server
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={deleting} onOpenChange={setDeleting}>
                <DialogContent>
                    <form onSubmit={destroy} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Delete {network.name}?</DialogTitle>
                            <DialogDescription>Type the network name to confirm.</DialogDescription>
                        </DialogHeader>
                        <Input value={deleteForm.data.name} onChange={(e) => deleteForm.setData('name', e.target.value)} placeholder={network.name} />
                        <InputError message={deleteForm.errors.name} />
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setDeleting(false)}>
                                Cancel
                            </Button>
                            <Button variant="destructive" disabled={deleteForm.processing}>
                                Delete network
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
