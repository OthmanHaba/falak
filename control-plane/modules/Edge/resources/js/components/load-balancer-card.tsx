import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { type DomainsPageProps } from '../types';

type Props = Pick<DomainsPageProps, 'loadBalancer' | 'lbServers' | 'targets' | 'policies'> & { siteId: string; canManage: boolean };

const NONE = '__none__';

export function LoadBalancerCard({ siteId, loadBalancer, lbServers, targets, policies, canManage }: Props) {
    const form = useForm({
        server_id: loadBalancer?.server_id ?? '',
        policy: loadBalancer?.policy ?? 'round_robin',
        health_uri: loadBalancer?.health_uri ?? '/',
        backend_port: loadBalancer?.backend_port ?? 80,
        weights: Object.fromEntries(targets.map((target) => [target.server_id, loadBalancer?.weights[target.server_id] ?? 1])) as Record<
            string,
            number
        >,
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.put(route('edge.load-balancer.update', siteId), { preserveScroll: true });
    };

    const remove = () => {
        if (window.confirm('Remove the load balancer? Targets will serve the site directly again.')) {
            router.delete(route('edge.load-balancer.destroy', siteId), { preserveScroll: true });
        }
    };

    if (lbServers.length === 0 && !loadBalancer) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>Load balancer</CardTitle>
                    <CardDescription>Create a server of type &quot;Load balancer&quot; to put this site behind one.</CardDescription>
                </CardHeader>
            </Card>
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Load balancer</CardTitle>
                <CardDescription>
                    The load balancer terminates TLS and proxies to the site&apos;s servers over their private network; targets then serve plain HTTP.
                    Point DNS at the load balancer.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label>Load balancer server</Label>
                            <Select
                                value={form.data.server_id || NONE}
                                onValueChange={(value) => form.setData('server_id', value === NONE ? '' : value)}
                                disabled={!canManage}
                            >
                                <SelectTrigger aria-label="Load balancer server">
                                    <SelectValue placeholder="None" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE} disabled>
                                        Choose a server
                                    </SelectItem>
                                    {lbServers.map((server) => (
                                        <SelectItem key={server.id} value={server.id}>
                                            {server.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.server_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label>Balancing policy</Label>
                            <Select value={form.data.policy} onValueChange={(value) => form.setData('policy', value)} disabled={!canManage}>
                                <SelectTrigger aria-label="Balancing policy">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {policies.map((policy) => (
                                        <SelectItem key={policy.value} value={policy.value}>
                                            {policy.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="lb-health">Health check path</Label>
                            <Input
                                id="lb-health"
                                value={form.data.health_uri}
                                onChange={(e) => form.setData('health_uri', e.target.value)}
                                placeholder="/up"
                                disabled={!canManage}
                            />
                            <InputError message={form.errors.health_uri} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="lb-port">Backend port</Label>
                            <Input
                                id="lb-port"
                                type="number"
                                min={1}
                                max={65535}
                                value={form.data.backend_port}
                                onChange={(e) => form.setData('backend_port', Number(e.target.value))}
                                disabled={!canManage}
                            />
                            <InputError message={form.errors.backend_port} />
                        </div>
                    </div>

                    {targets.length > 0 && (
                        <div className="space-y-2">
                            <Label>Weights</Label>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {targets.map((target) => (
                                    <div key={target.server_id} className="flex items-center gap-2 rounded-md border p-2">
                                        <span className="flex-1 text-sm">
                                            {target.name} <span className="text-muted-foreground text-xs">({target.role})</span>
                                        </span>
                                        <Input
                                            type="number"
                                            min={1}
                                            max={10}
                                            className="w-20"
                                            aria-label={`Weight of ${target.name}`}
                                            value={form.data.weights[target.server_id] ?? 1}
                                            onChange={(e) =>
                                                form.setData('weights', { ...form.data.weights, [target.server_id]: Number(e.target.value) })
                                            }
                                            disabled={!canManage}
                                        />
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {canManage && (
                        <div className="flex gap-2">
                            <Button type="submit" disabled={form.processing || !form.data.server_id}>
                                {loadBalancer ? 'Save' : 'Enable load balancer'}
                            </Button>
                            {loadBalancer && (
                                <Button type="button" variant="outline" onClick={remove}>
                                    Remove
                                </Button>
                            )}
                        </div>
                    )}
                </form>
            </CardContent>
        </Card>
    );
}
