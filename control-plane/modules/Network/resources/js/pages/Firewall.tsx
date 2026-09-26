import { CommandLog, TERMINAL_COMMAND_STATUSES, type CommandStatus } from '@/components/command-log';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { Pencil, Plus, RefreshCw, Shield, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { ApplyStatusBadge } from '../components/network-ui';
import { type FirewallStateSummary } from '../types';

type RuleAction = 'allow' | 'deny';
type RuleProtocol = 'tcp' | 'udp' | 'any';

interface Rule {
    id: string;
    name: string;
    action: RuleAction;
    protocol: RuleProtocol;
    port: string | null;
    source: string | null;
    is_default: boolean;
    created_at: string;
}

interface CompiledRule {
    id: string;
    protocol: string;
    ports?: string[];
    sources?: string[];
    interface?: string;
    comment?: string;
}

interface Props {
    server: { id: string; name: string; type_label: string; status: string; ipv4: string | null };
    rules: Rule[];
    networkRules: CompiledRule[];
    state: FirewallStateSummary | null;
    sshPort: number;
    can: { manage: boolean };
}

interface RuleForm {
    name: string;
    action: RuleAction;
    protocol: RuleProtocol;
    port: string;
    source: string;
}

const EMPTY: RuleForm = { name: '', action: 'allow', protocol: 'tcp', port: '', source: '' };

export default function Firewall({ server, rules, networkRules, state, sshPort, can }: Props) {
    const [editing, setEditing] = useState<Rule | 'new' | null>(null);
    const [deleting, setDeleting] = useState<Rule | null>(null);
    const form = useForm<RuleForm>(EMPTY);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Network', href: '/network' },
        { title: `${server.name} firewall`, href: `/network/servers/${server.id}/firewall` },
    ];

    const open = (rule: Rule | 'new') => {
        form.clearErrors();
        form.setData(
            rule === 'new'
                ? EMPTY
                : { name: rule.name, action: rule.action, protocol: rule.protocol, port: rule.port ?? '', source: rule.source ?? '' },
        );
        setEditing(rule);
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setEditing(null) };

        if (editing === 'new') {
            form.post(`/network/servers/${server.id}/firewall/rules`, options);
        } else if (editing) {
            form.put(`/network/servers/${server.id}/firewall/rules/${editing.id}`, options);
        }
    };

    const destroy = () => {
        if (!deleting) return;
        router.delete(`/network/servers/${server.id}/firewall/rules/${deleting.id}`, { preserveScroll: true, onFinish: () => setDeleting(null) });
    };

    const reapply = () => router.post(`/network/servers/${server.id}/firewall/apply`, {}, { preserveScroll: true });

    const onCommandStatus = (status: CommandStatus) => {
        if (TERMINAL_COMMAND_STATUSES.includes(status)) {
            router.reload({ only: ['state'] });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${server.name} firewall`} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={`${server.name} firewall`}
                        description={`${server.type_label} · ${server.ipv4 ?? 'no public IP'} · incoming traffic is dropped unless a rule allows it`}
                    />
                    {can.manage && (
                        <div className="flex gap-2">
                            <Button variant="outline" onClick={reapply} disabled={server.status !== 'active'}>
                                <RefreshCw /> Re-apply
                            </Button>
                            <Button onClick={() => open('new')}>
                                <Plus /> Add rule
                            </Button>
                        </div>
                    )}
                </div>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between gap-4">
                        <div className="space-y-1">
                            <CardTitle className="flex items-center gap-2">
                                <Shield className="size-4" /> Status
                            </CardTitle>
                            <CardDescription>
                                {state?.applied_at
                                    ? `Last applied ${formatDistanceToNow(new Date(state.applied_at), { addSuffix: true })}`
                                    : 'Never applied'}
                                {state && !state.in_sync && state.status === 'applied' ? ' · changes pending' : ''}
                            </CardDescription>
                        </div>
                        <ApplyStatusBadge status={state?.status} />
                    </CardHeader>
                    {(state?.error || state?.ruleset_sha256) && (
                        <CardContent className="space-y-2 text-sm">
                            {state.error && (
                                <Alert variant="destructive">
                                    <AlertDescription>{state.error}</AlertDescription>
                                </Alert>
                            )}
                            {state.ruleset_sha256 && (
                                <p className="text-muted-foreground font-mono text-xs break-all">ruleset sha256 {state.ruleset_sha256}</p>
                            )}
                        </CardContent>
                    )}
                </Card>

                <Card className="py-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Action</TableHead>
                                <TableHead>Protocol</TableHead>
                                <TableHead>Port</TableHead>
                                <TableHead>Source</TableHead>
                                {can.manage && <TableHead className="w-24" />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow className="text-muted-foreground">
                                <TableCell>SSH (lock-out protection)</TableCell>
                                <TableCell>allow</TableCell>
                                <TableCell className="uppercase">tcp</TableCell>
                                <TableCell className="font-mono">{sshPort}</TableCell>
                                <TableCell>anywhere</TableCell>
                                {can.manage && <TableCell />}
                            </TableRow>
                            {networkRules.map((rule) => (
                                <TableRow key={rule.id} className="text-muted-foreground">
                                    <TableCell>{rule.comment}</TableCell>
                                    <TableCell>allow</TableCell>
                                    <TableCell className="uppercase">{rule.protocol}</TableCell>
                                    <TableCell className="font-mono">
                                        {rule.ports?.join(', ') ?? (rule.interface ? `all on ${rule.interface}` : 'all')}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">{rule.sources?.join(', ') ?? 'peers'}</TableCell>
                                    {can.manage && <TableCell />}
                                </TableRow>
                            ))}
                            {rules.map((rule) => (
                                <TableRow key={rule.id}>
                                    <TableCell className="font-medium">
                                        {rule.name}
                                        {rule.is_default && (
                                            <Badge variant="outline" className="ml-2 text-xs">
                                                default
                                            </Badge>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <span
                                            className={
                                                rule.action === 'deny' ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400'
                                            }
                                        >
                                            {rule.action}
                                        </span>
                                    </TableCell>
                                    <TableCell className="uppercase">{rule.protocol}</TableCell>
                                    <TableCell className="font-mono">{rule.port ?? 'all'}</TableCell>
                                    <TableCell className="font-mono text-xs">{rule.source ?? 'anywhere'}</TableCell>
                                    {can.manage && (
                                        <TableCell className="text-right whitespace-nowrap">
                                            <Button variant="ghost" size="icon" onClick={() => open(rule)} aria-label={`Edit ${rule.name}`}>
                                                <Pencil />
                                            </Button>
                                            <Button variant="ghost" size="icon" onClick={() => setDeleting(rule)} aria-label={`Delete ${rule.name}`}>
                                                <Trash2 />
                                            </Button>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
                <p className="text-muted-foreground text-xs">Deny rules are evaluated before allow rules. ICMP (ping) is always allowed.</p>

                {state?.command_id && state.status === 'applying' && <CommandLog commandId={state.command_id} onStatusChange={onCommandStatus} />}
            </div>

            <Dialog open={editing !== null} onOpenChange={(value) => !value && setEditing(null)}>
                <DialogContent>
                    <form onSubmit={submit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>{editing === 'new' ? 'Add rule' : 'Edit rule'}</DialogTitle>
                            <DialogDescription>Leave port empty for all ports and source empty for anywhere.</DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label htmlFor="rule-name">Name</Label>
                            <Input
                                id="rule-name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="PostgreSQL from app servers"
                            />
                            <InputError message={form.errors.name} />
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <Label>Action</Label>
                                <Select value={form.data.action} onValueChange={(value) => form.setData('action', value as RuleAction)}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="allow">Allow</SelectItem>
                                        <SelectItem value="deny">Deny</SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.action} />
                            </div>
                            <div className="grid gap-2">
                                <Label>Protocol</Label>
                                <Select value={form.data.protocol} onValueChange={(value) => form.setData('protocol', value as RuleProtocol)}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="tcp">TCP</SelectItem>
                                        <SelectItem value="udp">UDP</SelectItem>
                                        <SelectItem value="any">Any</SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.protocol} />
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="rule-port">Port or range</Label>
                                <Input
                                    id="rule-port"
                                    value={form.data.port}
                                    onChange={(e) => form.setData('port', e.target.value)}
                                    placeholder="5432 or 8000-8100"
                                    className="font-mono"
                                />
                                <InputError message={form.errors.port} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="rule-source">Source</Label>
                                <Input
                                    id="rule-source"
                                    value={form.data.source}
                                    onChange={(e) => form.setData('source', e.target.value)}
                                    placeholder="10.0.0.0/8"
                                    className="font-mono"
                                />
                                <InputError message={form.errors.source} />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setEditing(null)}>
                                Cancel
                            </Button>
                            <Button disabled={form.processing}>{editing === 'new' ? 'Add rule' : 'Save rule'}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={deleting !== null} onOpenChange={(value) => !value && setDeleting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete {deleting?.name}?</DialogTitle>
                        <DialogDescription>The updated ruleset is applied to {server.name} immediately.</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setDeleting(null)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={destroy}>
                            Delete rule
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
