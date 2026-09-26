import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import SiteLayout from '@/layouts/site-layout';
import { router } from '@inertiajs/react';
import { ArrowRight, MoreHorizontal, Plus, RefreshCw, Star } from 'lucide-react';
import { useState } from 'react';
import { CertificatesCard } from '../components/certificates-card';
import { DnsCredentialsCard } from '../components/dns-credentials-card';
import { DomainDialog } from '../components/domain-dialog';
import { StatusBadge, TlsBadge, formatDate } from '../components/edge-ui';
import { LoadBalancerCard } from '../components/load-balancer-card';
import { type DomainsPageProps, type EdgeDomain } from '../types';

export default function Domains(props: DomainsPageProps) {
    const { site, testDomain, domains, certificates, dnsCredentials, dnsProviders, tlsModes, edgeServers, can } = props;
    const [editing, setEditing] = useState<EdgeDomain | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    const openDialog = (domain: EdgeDomain | null) => {
        setEditing(domain);
        setDialogOpen(true);
    };

    const makePrimary = (domain: EdgeDomain) => router.put(route('edge.domains.primary', [site.id, domain.id]), {}, { preserveScroll: true });

    const remove = (domain: EdgeDomain) => {
        if (window.confirm(`Remove ${domain.name}? It stops being served immediately.`)) {
            router.delete(route('edge.domains.destroy', [site.id, domain.id]), { preserveScroll: true });
        }
    };

    const reapply = (serverId: string) => router.post(route('edge.apply', site.id), { server_id: serverId }, { preserveScroll: true });

    return (
        <SiteLayout
            site={site}
            title="Domains & TLS"
            actions={
                can.manage && (
                    <Button onClick={() => openDialog(null)}>
                        <Plus /> Add domain
                    </Button>
                )
            }
        >
            <Card>
                <CardHeader>
                    <CardTitle>Domains</CardTitle>
                    <CardDescription>The primary domain is the canonical host; www redirects send visitors to the served host.</CardDescription>
                </CardHeader>
                <CardContent className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-6">Domain</TableHead>
                                <TableHead>TLS</TableHead>
                                <TableHead>Redirect</TableHead>
                                <TableHead className="w-12 pr-6" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {domains.map((domain) => (
                                <TableRow key={domain.id}>
                                    <TableCell className="pl-6">
                                        <div className="flex items-center gap-2">
                                            <a
                                                href={`${domain.tls_mode === 'off' ? 'http' : 'https'}://${domain.served_host}`}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="font-medium hover:underline"
                                            >
                                                {domain.served_host}
                                            </a>
                                            {domain.is_primary && <Badge>Primary</Badge>}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <TlsBadge mode={domain.tls_mode} />
                                    </TableCell>
                                    <TableCell className="text-muted-foreground text-sm">
                                        {domain.hosts.length > 1 ? (
                                            <span className="inline-flex items-center gap-1">
                                                {domain.hosts[1]} <ArrowRight className="size-3" /> {domain.hosts[0]}
                                            </span>
                                        ) : (
                                            '—'
                                        )}
                                    </TableCell>
                                    <TableCell className="pr-6 text-right">
                                        {can.manage && (
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button variant="ghost" size="icon" aria-label={`Actions for ${domain.name}`}>
                                                        <MoreHorizontal />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuItem onSelect={() => openDialog(domain)}>Edit</DropdownMenuItem>
                                                    {!domain.is_primary && (
                                                        <DropdownMenuItem onSelect={() => makePrimary(domain)}>
                                                            <Star /> Make primary
                                                        </DropdownMenuItem>
                                                    )}
                                                    <DropdownMenuItem className="text-destructive" onSelect={() => remove(domain)}>
                                                        Remove
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                            {testDomain && (
                                <TableRow>
                                    <TableCell className="pl-6">
                                        <div className="flex items-center gap-2">
                                            <a
                                                href={`https://${testDomain}`}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="font-medium hover:underline"
                                            >
                                                {testDomain}
                                            </a>
                                            <Badge variant="outline">Test domain</Badge>
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <TlsBadge mode="auto" />
                                    </TableCell>
                                    <TableCell className="text-muted-foreground text-sm">—</TableCell>
                                    <TableCell className="pr-6" />
                                </TableRow>
                            )}
                            {domains.length === 0 && !testDomain && (
                                <TableRow>
                                    <TableCell colSpan={4} className="text-muted-foreground py-8 text-center">
                                        No domains yet.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            <div className="grid gap-6 lg:grid-cols-2">
                <CertificatesCard siteId={site.id} certificates={certificates} canManage={can.manage} />
                {(can.manage_dns || dnsCredentials.length > 0) && (
                    <DnsCredentialsCard credentials={dnsCredentials} providers={dnsProviders} canManage={can.manage_dns} />
                )}
            </div>

            <LoadBalancerCard
                siteId={site.id}
                loadBalancer={props.loadBalancer}
                lbServers={props.lbServers}
                targets={props.targets}
                policies={props.policies}
                canManage={can.manage}
            />

            <Card>
                <CardHeader>
                    <CardTitle>Edge status</CardTitle>
                    <CardDescription>
                        Every change recompiles the full Caddy configuration of each server below and applies it atomically. Route id{' '}
                        <code className="font-mono text-xs">{props.routeId}</code>.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-2">
                    {edgeServers.length === 0 && <p className="text-muted-foreground text-sm">The site has no servers yet.</p>}
                    {edgeServers.map((server) => (
                        <div key={server.id} className="flex flex-wrap items-center gap-3 rounded-md border p-3">
                            <div className="min-w-0 flex-1">
                                <div className="text-sm font-medium">
                                    {server.name} <span className="text-muted-foreground text-xs capitalize">· {server.role}</span>
                                </div>
                                <div className="text-muted-foreground text-xs">
                                    {!server.serves_http
                                        ? 'Does not serve HTTP (no edge).'
                                        : server.state
                                          ? `Dispatched ${formatDate(server.state.dispatched_at)} · applied ${formatDate(server.state.applied_at)}${server.state.routes !== null ? ` · ${server.state.routes} routes` : ''}`
                                          : 'Not applied yet.'}
                                </div>
                                {server.state?.error && <div className="text-destructive mt-1 text-xs">{server.state.error}</div>}
                            </div>
                            {server.state && <StatusBadge status={server.state.status} />}
                            {can.manage && server.serves_http && (
                                <Button size="sm" variant="outline" onClick={() => reapply(server.id)}>
                                    <RefreshCw /> Re-apply
                                </Button>
                            )}
                        </div>
                    ))}
                </CardContent>
            </Card>

            <DomainDialog
                siteId={site.id}
                domain={editing}
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                tlsModes={tlsModes}
                certificates={certificates}
                dnsCredentials={dnsCredentials}
            />
        </SiteLayout>
    );
}
