import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import SiteLayout from '@/layouts/site-layout';
import { router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { EnvEditor, Field, firstError, ServerPicker } from '../components/process-ui';
import { ProcessStatusCard } from '../components/status-card';
import { type EnvRow, type SharedProps } from '../types';

interface DaemonRow {
    id: string;
    program: string;
    name: string;
    command: string;
    directory: string | null;
    user: string | null;
    instances: number;
    restart: string;
    stop_signal: string;
    stop_timeout: number;
    env: EnvRow[];
    server_ids: string[];
}

interface Props extends SharedProps {
    daemons: DaemonRow[];
    defaults: { directory: string; user: string; php: string | null; runtime: string };
    options: { restart: string[]; stop_signals: string[] };
    containerRuntime: boolean;
}

interface DaemonForm {
    name: string;
    command: string;
    directory: string;
    user: string;
    instances: string;
    restart: string;
    stop_signal: string;
    stop_timeout: string;
    env: EnvRow[];
    server_ids: string[];
}

const RESTART_LABELS: Record<string, string> = { always: 'Always', 'on-failure': 'On failure', never: 'Never' };

function starter(runtime: string, php: string | null): string {
    switch (runtime) {
        case 'node':
            return 'node server.js';
        case 'bun':
            return 'bun run start';
        case 'deno':
            return 'deno task start';
        default:
            return php ? `${php} artisan reverb:start` : '';
    }
}

export default function Daemons(props: Props) {
    const { site, servers, daemons, defaults, options, containerRuntime, can } = props;
    const [editing, setEditing] = useState<DaemonRow | 'new' | null>(null);

    const destroy = (daemon: DaemonRow) => {
        if (window.confirm(`Stop and remove ${daemon.name} on every server?`)) {
            router.delete(`/sites/${site.id}/daemons/${daemon.id}`, { preserveScroll: true });
        }
    };

    return (
        <SiteLayout
            site={site}
            title="Daemons"
            actions={
                can.manage &&
                !containerRuntime && (
                    <Button size="sm" onClick={() => setEditing('new')}>
                        <Plus /> Add daemon
                    </Button>
                )
            }
        >
            <ProcessStatusCard {...props} kinds={['app', 'daemon']} title="Status" />

            <Card>
                <CardHeader>
                    <CardTitle>Daemons</CardTitle>
                    <CardDescription>
                        Long-running commands (websocket servers, app servers, custom consumers), restarted by the agent when they exit.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {daemons.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No daemons.</p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Daemon</TableHead>
                                    <TableHead>Runs as</TableHead>
                                    <TableHead>Instances</TableHead>
                                    <TableHead>Restart</TableHead>
                                    <TableHead className="w-24" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {daemons.map((daemon) => (
                                    <TableRow key={daemon.id}>
                                        <TableCell className="max-w-md">
                                            <div>{daemon.name}</div>
                                            <div className="text-muted-foreground truncate font-mono text-xs" title={daemon.command}>
                                                {daemon.command}
                                            </div>
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">{daemon.user ?? defaults.user}</TableCell>
                                        <TableCell>{daemon.instances}</TableCell>
                                        <TableCell className="text-xs">{RESTART_LABELS[daemon.restart] ?? daemon.restart}</TableCell>
                                        <TableCell className="text-right">
                                            {can.manage && (
                                                <div className="flex justify-end gap-1">
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        onClick={() => setEditing(daemon)}
                                                        aria-label={`Edit ${daemon.name}`}
                                                    >
                                                        <Pencil className="size-4" />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        onClick={() => destroy(daemon)}
                                                        aria-label={`Remove ${daemon.name}`}
                                                    >
                                                        <Trash2 className="size-4" />
                                                    </Button>
                                                </div>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {editing !== null && (
                <DaemonDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    siteId={site.id}
                    daemon={editing === 'new' ? null : editing}
                    servers={servers}
                    defaults={defaults}
                    options={options}
                    onClose={() => setEditing(null)}
                />
            )}
        </SiteLayout>
    );
}

function DaemonDialog({
    siteId,
    daemon,
    servers,
    defaults,
    options,
    onClose,
}: {
    siteId: string;
    daemon: DaemonRow | null;
    servers: SharedProps['servers'];
    defaults: Props['defaults'];
    options: Props['options'];
    onClose: () => void;
}) {
    const form = useForm<DaemonForm>({
        name: daemon?.name ?? '',
        command: daemon?.command ?? starter(defaults.runtime, defaults.php),
        directory: daemon?.directory ?? '',
        user: daemon?.user ?? '',
        instances: String(daemon?.instances ?? 1),
        restart: daemon?.restart ?? 'always',
        stop_signal: daemon?.stop_signal ?? 'TERM',
        stop_timeout: String(daemon?.stop_timeout ?? 30),
        env: daemon?.env ?? [],
        server_ids: daemon?.server_ids ?? [],
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, env: data.env.filter((row) => row.key.trim() !== '') }));
        const opts = { preserveScroll: true, onSuccess: onClose };

        if (daemon) {
            form.put(`/sites/${siteId}/daemons/${daemon.id}`, opts);
        } else {
            form.post(`/sites/${siteId}/daemons`, opts);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>{daemon ? `Edit ${daemon.name}` : 'Add daemon'}</DialogTitle>
                        <DialogDescription>
                            The command runs with bash; the agent captures its output and ships it to the site logs.
                        </DialogDescription>
                    </DialogHeader>

                    <Field label="Name" error={form.errors.name}>
                        <Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} placeholder="Reverb" />
                    </Field>
                    <Field label="Command" error={form.errors.command}>
                        <Textarea
                            value={form.data.command}
                            onChange={(event) => form.setData('command', event.target.value)}
                            className="font-mono"
                            rows={2}
                        />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Directory" error={form.errors.directory} hint={`Default: ${defaults.directory}`}>
                            <Input
                                value={form.data.directory}
                                onChange={(event) => form.setData('directory', event.target.value)}
                                placeholder={defaults.directory}
                            />
                        </Field>
                        <Field label="User" error={form.errors.user} hint={`Default: ${defaults.user}`}>
                            <Input
                                value={form.data.user}
                                onChange={(event) => form.setData('user', event.target.value)}
                                placeholder={defaults.user}
                            />
                        </Field>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-4">
                        <Field label="Instances" error={form.errors.instances}>
                            <Input
                                type="number"
                                min={1}
                                max={64}
                                value={form.data.instances}
                                onChange={(event) => form.setData('instances', event.target.value)}
                            />
                        </Field>
                        <Field label="Restart" error={form.errors.restart}>
                            <Select value={form.data.restart} onValueChange={(value) => form.setData('restart', value)}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.restart.map((value) => (
                                        <SelectItem key={value} value={value}>
                                            {RESTART_LABELS[value] ?? value}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field label="Stop signal" error={form.errors.stop_signal}>
                            <Select value={form.data.stop_signal} onValueChange={(value) => form.setData('stop_signal', value)}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.stop_signals.map((value) => (
                                        <SelectItem key={value} value={value}>
                                            SIG{value}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field label="Stop timeout (s)" error={form.errors.stop_timeout}>
                            <Input
                                type="number"
                                min={1}
                                value={form.data.stop_timeout}
                                onChange={(event) => form.setData('stop_timeout', event.target.value)}
                            />
                        </Field>
                    </div>

                    <ServerPicker servers={servers} value={form.data.server_ids} onChange={(ids) => form.setData('server_ids', ids)} />
                    <EnvEditor rows={form.data.env} onChange={(rows) => form.setData('env', rows)} error={firstError(form.errors, 'env')} />

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
