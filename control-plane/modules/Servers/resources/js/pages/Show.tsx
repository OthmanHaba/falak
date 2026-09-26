import { CommandLog, TERMINAL_COMMAND_STATUSES, type CommandStatus } from '@/components/command-log';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useEchoChannel } from '@/hooks/use-echo-channel';
import AppLayout from '@/layouts/app-layout';
import { shellContext } from '@/lib/registry';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { Activity, ChevronDown, Pencil, RefreshCw, RotateCw, ScrollText, Trash2 } from 'lucide-react';
import { FormEventHandler, lazy, Suspense, useEffect, useRef, useState } from 'react';
import { PhpVersionsCard } from '../components/php-versions-card';
import { AgentDot, CopyButton, formatBytes, ServerStatusBadge } from '../components/server-ui';
import { SshKeysCard } from '../components/ssh-keys-card';
import { type AgentDetails, type MetricSample, type PhpVersionRow, type ServerDetails, type SshKeyOption } from '../types';

// recharts is heavy; load the chart on demand.
const MetricsChart = lazy(() => import('../components/metrics-chart').then((m) => ({ default: m.MetricsChart })));

interface Props {
    server: ServerDetails;
    agent: AgentDetails | null;
    metrics: MetricSample[];
    php: PhpVersionRow[];
    phpOptions: string[];
    sshKeys: (SshKeyOption & { unix_user: string | null })[];
    availableSshKeys: SshKeyOption[];
    can: { update: boolean; delete: boolean };
}

function relative(value: string | null | undefined): string {
    return value ? formatDistanceToNow(new Date(value), { addSuffix: true }) : '—';
}

function Detail({ label, value, copy }: { label: string; value: React.ReactNode; copy?: string | null }) {
    return (
        <div className="min-w-0">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="flex items-center gap-1 truncate text-sm">
                {value ?? '—'}
                {copy && <CopyButton value={copy} label={`Copy ${label}`} />}
            </dd>
        </div>
    );
}

export default function Show({ server, agent, metrics, php, phpOptions, sshKeys, availableSshKeys, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
    ];

    const canViewTelemetry = shellContext(usePage<SharedData>().props).can('telemetry.view');
    const [renaming, setRenaming] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [logOpen, setLogOpen] = useState(server.status !== 'active');

    useEffect(() => {
        if (server.status !== 'active') setLogOpen(true);
    }, [server.status]);

    const reload = () => router.reload({ only: ['server', 'agent', 'php'] });
    const previousLogStatus = useRef<CommandStatus | null>(null);

    // Refresh the page state when provisioning finishes while we watch (not on the initial load).
    const onProvisionStatus = (status: CommandStatus) => {
        const previous = previousLogStatus.current;
        previousLogStatus.current = status;

        if (previous !== null && !TERMINAL_COMMAND_STATUSES.includes(previous) && TERMINAL_COMMAND_STATUSES.includes(status)) {
            reload();
        }
    };

    useEchoChannel(`servers.${server.id}`, ['server.updated'], reload);

    const reprovision = () => router.post(route('servers.reprovision', server.id), {}, { preserveScroll: true });
    const regenerate = () => router.post(route('servers.install-command', server.id), {}, { preserveScroll: true });
    const reinstallAgent = () => {
        if (window.confirm('Create a new agent install command? Running it on a host enrolls that host and revokes the current agent.')) {
            regenerate();
        }
    };

    const showProvisioning = server.provision_command_id !== null;
    const canReprovision = can.update && (server.status === 'error' || server.status === 'active');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={server.name} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <h1 className="text-xl font-semibold">{server.name}</h1>
                            {can.update && (
                                <Button variant="ghost" size="icon" className="size-7" onClick={() => setRenaming(true)} aria-label="Rename server">
                                    <Pencil className="size-3.5" />
                                </Button>
                            )}
                            <ServerStatusBadge status={server.status} />
                            <AgentDot status={agent?.status} />
                        </div>
                        <p className="text-muted-foreground text-sm">
                            {server.type_label} · {server.provider_label}
                            {server.region ? ` · ${server.region}` : ''}
                            {server.size ? ` · ${server.size}` : ''}
                        </p>
                        {server.status_message && (
                            <p className={server.status === 'error' ? 'text-destructive text-sm' : 'text-muted-foreground text-sm'}>
                                {server.status_message}
                            </p>
                        )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {canViewTelemetry && (
                            <>
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={`/telemetry/servers/${server.id}/metrics`}>
                                        <Activity /> Metrics
                                    </Link>
                                </Button>
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={`/telemetry/logs?server_id=${encodeURIComponent(server.id)}`}>
                                        <ScrollText /> Logs
                                    </Link>
                                </Button>
                            </>
                        )}
                        <Button variant="outline" size="sm" onClick={reload}>
                            <RefreshCw /> Refresh
                        </Button>
                        {canReprovision && (
                            <Button variant="outline" size="sm" onClick={reprovision}>
                                <RotateCw /> Re-provision
                            </Button>
                        )}
                    </div>
                </div>

                {!server.install_command && server.can_regenerate_install_command && server.status !== 'deleting' && (
                    <div className="flex justify-end">
                        <Button variant="ghost" size="sm" onClick={reinstallAgent}>
                            <RefreshCw /> Reinstall agent
                        </Button>
                    </div>
                )}

                {server.install_command && (
                    <Card className="border-primary/40">
                        <CardHeader>
                            <CardTitle>Install the Kiln agent</CardTitle>
                            <CardDescription>
                                Run this once as root on the server (Ubuntu LTS). The link is single-use; provisioning starts when the agent enrolls.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="flex items-center gap-2 rounded-md bg-neutral-950 p-3 font-mono text-xs text-neutral-100">
                                <code className="flex-1 break-all" data-testid="install-command">
                                    {server.install_command}
                                </code>
                                <CopyButton
                                    value={server.install_command}
                                    label="Copy install command"
                                    className="text-neutral-100 hover:bg-neutral-800"
                                />
                            </div>
                            {server.can_regenerate_install_command && (
                                <Button variant="outline" size="sm" onClick={regenerate}>
                                    <RefreshCw /> Regenerate command
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                                <Detail
                                    label="Public IPv4"
                                    value={server.ipv4 && <span className="font-mono">{server.ipv4}</span>}
                                    copy={server.ipv4}
                                />
                                <Detail
                                    label="Public IPv6"
                                    value={server.ipv6 && <span className="font-mono">{server.ipv6}</span>}
                                    copy={server.ipv6}
                                />
                                <Detail
                                    label="Private IPv4"
                                    value={server.private_ipv4 && <span className="font-mono">{server.private_ipv4}</span>}
                                    copy={server.private_ipv4}
                                />
                                <Detail label="SSH port" value={server.ssh_port} />
                                <Detail label="OS" value={server.os} />
                                <Detail label="Architecture" value={server.arch} />
                                <Detail label="CPUs" value={server.cpus} />
                                <Detail label="Memory" value={formatBytes(server.memory_bytes)} />
                                <Detail label="Disk" value={formatBytes(server.disk_bytes)} />
                                <Detail label="Timezone" value={server.timezone} />
                                <Detail label="Provisioned" value={relative(server.provisioned_at)} />
                                <Detail label="Created" value={relative(server.created_at)} />
                                {server.provider_server_id && (
                                    <Detail label="Provider ID" value={<span className="font-mono">{server.provider_server_id}</span>} />
                                )}
                                <Detail
                                    label="Stack"
                                    value={
                                        [
                                            server.stack.php && `${server.stack.php.runtime === 'fpm' ? 'PHP-FPM' : 'FrankenPHP'}`,
                                            server.stack.node && `Node ${server.stack.node}`,
                                            server.stack.database,
                                            server.stack.cache,
                                            server.stack.docker && 'Docker',
                                        ]
                                            .filter(Boolean)
                                            .join(' · ') || '—'
                                    }
                                />
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Agent</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {agent ? (
                                <dl className="grid grid-cols-2 gap-4">
                                    <Detail label="Status" value={<AgentDot status={agent.status} />} />
                                    <Detail label="Version" value={agent.version} />
                                    <Detail label="Last heartbeat" value={relative(agent.last_heartbeat_at)} />
                                    <Detail label="Enrolled" value={relative(agent.enrolled_at)} />
                                    <Detail label="Certificate expires" value={relative(agent.certificate_expires_at)} />
                                    <Detail label="Kernel" value={agent.kernel} />
                                    <Detail label="Docker" value={agent.docker ?? 'not installed'} />
                                    <Detail
                                        label="Uptime"
                                        value={
                                            agent.metrics.uptime_s !== undefined
                                                ? `${Math.floor(agent.metrics.uptime_s / 86400)}d ${Math.floor((agent.metrics.uptime_s % 86400) / 3600)}h`
                                                : '—'
                                        }
                                    />
                                </dl>
                            ) : (
                                <p className="text-muted-foreground text-sm">No agent enrolled yet.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {showProvisioning && (
                    <Collapsible open={logOpen} onOpenChange={setLogOpen}>
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <div>
                                    <CardTitle>Provisioning log</CardTitle>
                                    <CardDescription>Output of the latest provision.apply run.</CardDescription>
                                </div>
                                <CollapsibleTrigger asChild>
                                    <Button variant="ghost" size="sm">
                                        <ChevronDown className={logOpen ? 'rotate-180 transition-transform' : 'transition-transform'} />
                                        {logOpen ? 'Hide' : 'Show'}
                                    </Button>
                                </CollapsibleTrigger>
                            </CardHeader>
                            <CollapsibleContent>
                                <CardContent>
                                    <CommandLog commandId={server.provision_command_id} onStatusChange={onProvisionStatus} />
                                </CardContent>
                            </CollapsibleContent>
                        </Card>
                    </Collapsible>
                )}

                {agent && (
                    <Suspense fallback={<div className="bg-muted/40 h-72 animate-pulse rounded-xl border" />}>
                        <MetricsChart serverId={server.id} initial={metrics} memoryBytes={server.memory_bytes} diskBytes={server.disk_bytes} />
                    </Suspense>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    {server.stack.php && (
                        <PhpVersionsCard
                            serverId={server.id}
                            versions={php}
                            options={phpOptions}
                            canUpdate={can.update}
                            serverActive={server.status === 'active'}
                        />
                    )}
                    <SshKeysCard serverId={server.id} attached={sshKeys} available={availableSshKeys} canUpdate={can.update} />
                </div>

                {can.delete && (
                    <Card className="border-destructive/40">
                        <CardHeader className="flex flex-row items-center justify-between gap-4">
                            <div>
                                <CardTitle>Delete server</CardTitle>
                                <CardDescription>Revokes the agent and removes the server from Kiln.</CardDescription>
                            </div>
                            <Button variant="destructive" onClick={() => setDeleting(true)} disabled={server.status === 'deleting'}>
                                <Trash2 /> Delete
                            </Button>
                        </CardHeader>
                    </Card>
                )}
            </div>

            {renaming && <RenameDialog server={server} onClose={() => setRenaming(false)} />}
            {deleting && <DeleteDialog server={server} onClose={() => setDeleting(false)} />}
        </AppLayout>
    );
}

function RenameDialog({ server, onClose }: { server: ServerDetails; onClose: () => void }) {
    const form = useForm({ name: server.name });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.patch(route('servers.update', server.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Rename server</DialogTitle>
                        <DialogDescription>The hostname on the machine is set at provisioning time and is not changed.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="rename">Name</Label>
                        <Input id="rename" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus />
                        <InputError message={form.errors.name} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button disabled={form.processing}>Save</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function DeleteDialog({ server, onClose }: { server: ServerDetails; onClose: () => void }) {
    const isCustom = server.provider === 'custom';
    const form = useForm({ name: '', destroy_at_provider: !isCustom });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.delete(route('servers.destroy', server.id), { onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Delete {server.name}?</DialogTitle>
                        <DialogDescription>This cannot be undone. Type the server name to confirm.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="confirm-name">Server name</Label>
                        <Input
                            id="confirm-name"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            placeholder={server.name}
                        />
                        <InputError message={form.errors.name} />
                    </div>
                    {!isCustom && (
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={form.data.destroy_at_provider}
                                onCheckedChange={(checked) => form.setData('destroy_at_provider', checked === true)}
                            />
                            Also destroy the machine at {server.provider_label}
                        </label>
                    )}
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button variant="destructive" disabled={form.processing || form.data.name !== server.name}>
                            Delete server
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
