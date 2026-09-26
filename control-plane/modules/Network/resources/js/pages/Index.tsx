import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { Network, Plus, Scale, Shield } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { ApplyStatusBadge } from '../components/network-ui';
import { type FirewallStateSummary, type PrivateAddress } from '../types';

interface ServerRow {
    id: string;
    name: string;
    type: string;
    type_label: string;
    status: string;
    ipv4: string | null;
    rules_count: number;
    firewall: FirewallStateSummary | null;
    private_addresses: PrivateAddress[];
}

interface NetworkRow {
    id: string;
    name: string;
    cidr: string;
    interface: string;
    listen_port: number;
    members_count: number;
}

interface LoadBalancerRow {
    id: string;
    name: string;
    status: string;
    ipv4: string | null;
    ipv6: string | null;
    private_addresses: PrivateAddress[];
}

interface Props {
    servers: ServerRow[];
    networks: NetworkRow[];
    loadBalancers: LoadBalancerRow[];
    defaults: { cidr: string; listen_port: number };
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Network', href: '/network' }];

function Addresses({ addresses }: { addresses: PrivateAddress[] }) {
    if (addresses.length === 0) {
        return <span className="text-muted-foreground text-xs">—</span>;
    }

    return (
        <div className="flex flex-col gap-0.5">
            {addresses.map((address) => (
                <span key={address.network_id} className="font-mono text-xs">
                    {address.address} <span className="text-muted-foreground font-sans">({address.network})</span>
                </span>
            ))}
        </div>
    );
}

export default function Index({ servers, networks, loadBalancers, defaults, can }: Props) {
    const [creating, setCreating] = useState(false);
    const form = useForm({ name: '', cidr: defaults.cidr, listen_port: String(defaults.listen_port) });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post('/network/private-networks', { preserveScroll: true, onSuccess: () => setCreating(false) });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Network" />
            <div className="space-y-8 p-4">
                <Heading title="Network" description="Firewalls, WireGuard private networks and load balancers" />

                <section id="firewalls" className="space-y-3">
                    <div className="flex items-center gap-2">
                        <Shield className="text-muted-foreground size-4" />
                        <h3 className="font-semibold">Firewalls</h3>
                    </div>
                    {servers.length === 0 ? (
                        <Card>
                            <CardContent className="text-muted-foreground py-8 text-center text-sm">No servers yet.</CardContent>
                        </Card>
                    ) : (
                        <Card className="py-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Server</TableHead>
                                        <TableHead>Public IP</TableHead>
                                        <TableHead>Private addresses</TableHead>
                                        <TableHead>Rules</TableHead>
                                        <TableHead>Firewall</TableHead>
                                        <TableHead className="w-24" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {servers.map((server) => (
                                        <TableRow key={server.id}>
                                            <TableCell>
                                                <div className="font-medium">{server.name}</div>
                                                <div className="text-muted-foreground text-xs">{server.type_label}</div>
                                            </TableCell>
                                            <TableCell className="font-mono text-xs">{server.ipv4 ?? '—'}</TableCell>
                                            <TableCell>
                                                <Addresses addresses={server.private_addresses} />
                                            </TableCell>
                                            <TableCell className="tabular-nums">{server.rules_count}</TableCell>
                                            <TableCell>
                                                <ApplyStatusBadge status={server.firewall?.status} />
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Button asChild variant="outline" size="sm">
                                                    <Link href={`/network/servers/${server.id}/firewall`}>Rules</Link>
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Card>
                    )}
                </section>

                <section className="space-y-3">
                    <div className="flex items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                            <Network className="text-muted-foreground size-4" />
                            <h3 className="font-semibold">Private networks</h3>
                        </div>
                        {can.manage && (
                            <Button size="sm" onClick={() => setCreating(true)}>
                                <Plus /> New network
                            </Button>
                        )}
                    </div>
                    {networks.length === 0 ? (
                        <Card>
                            <CardContent className="flex flex-col items-center gap-2 py-10 text-center">
                                <Network className="text-muted-foreground size-8" />
                                <p className="font-medium">No private networks</p>
                                <p className="text-muted-foreground text-sm">
                                    Connect servers over an encrypted WireGuard mesh so databases and caches never listen publicly.
                                </p>
                            </CardContent>
                        </Card>
                    ) : (
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {networks.map((network) => (
                                <Link key={network.id} href={`/network/private-networks/${network.id}`}>
                                    <Card className="hover:border-foreground/30 h-full transition-colors">
                                        <CardHeader>
                                            <CardTitle>{network.name}</CardTitle>
                                            <CardDescription className="font-mono">{network.cidr}</CardDescription>
                                        </CardHeader>
                                        <CardContent className="text-muted-foreground flex gap-4 text-sm">
                                            <span>
                                                {network.members_count} server{network.members_count === 1 ? '' : 's'}
                                            </span>
                                            <span className="font-mono">{network.interface}</span>
                                            <span>UDP {network.listen_port}</span>
                                        </CardContent>
                                    </Card>
                                </Link>
                            ))}
                        </div>
                    )}
                </section>

                <section className="space-y-3">
                    <div className="flex items-center gap-2">
                        <Scale className="text-muted-foreground size-4" />
                        <h3 className="font-semibold">Load balancers</h3>
                    </div>
                    {loadBalancers.length === 0 ? (
                        <Card>
                            <CardContent className="text-muted-foreground py-8 text-center text-sm">
                                No load balancer servers. Create a server of type “Load balancer” to route traffic to web servers.
                            </CardContent>
                        </Card>
                    ) : (
                        <Card className="py-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Public IPv4</TableHead>
                                        <TableHead>Public IPv6</TableHead>
                                        <TableHead>Private addresses</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {loadBalancers.map((lb) => (
                                        <TableRow key={lb.id}>
                                            <TableCell className="font-medium">
                                                <Link href={`/servers/${lb.id}`} className="hover:underline">
                                                    {lb.name}
                                                </Link>
                                            </TableCell>
                                            <TableCell className="font-mono text-xs">{lb.ipv4 ?? '—'}</TableCell>
                                            <TableCell className="font-mono text-xs">{lb.ipv6 ?? '—'}</TableCell>
                                            <TableCell>
                                                <Addresses addresses={lb.private_addresses} />
                                            </TableCell>
                                            <TableCell className="capitalize">{lb.status}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Card>
                    )}
                    <p className="text-muted-foreground text-xs">Routes and upstreams are configured on each site's domains (Edge).</p>
                </section>
            </div>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent>
                    <form onSubmit={submit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>New private network</DialogTitle>
                            <DialogDescription>
                                Servers you add get an address in this range and a WireGuard tunnel to every other member.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label htmlFor="network-name">Name</Label>
                            <Input
                                id="network-name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="backplane"
                            />
                            <InputError message={form.errors.name} />
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="network-cidr">Address range</Label>
                                <Input
                                    id="network-cidr"
                                    value={form.data.cidr}
                                    onChange={(e) => form.setData('cidr', e.target.value)}
                                    className="font-mono"
                                />
                                <InputError message={form.errors.cidr} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="network-port">UDP port</Label>
                                <Input
                                    id="network-port"
                                    inputMode="numeric"
                                    value={form.data.listen_port}
                                    onChange={(e) => form.setData('listen_port', e.target.value)}
                                    className="font-mono"
                                />
                                <InputError message={form.errors.listen_port} />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setCreating(false)}>
                                Cancel
                            </Button>
                            <Button disabled={form.processing}>Create network</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
