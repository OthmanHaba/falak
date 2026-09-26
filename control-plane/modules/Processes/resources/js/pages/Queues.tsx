import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import SiteLayout from '@/layouts/site-layout';
import { Link, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { EnvEditor, Field, firstError, ServerPicker } from '../components/process-ui';
import { ProcessStatusCard } from '../components/status-card';
import { type EnvRow, type SharedProps } from '../types';

interface WorkerRow {
    id: string;
    program: string;
    label: string;
    connection: string | null;
    queue: string | null;
    command: string | null;
    processes: number;
    timeout: number;
    sleep: number;
    tries: number;
    backoff: number | null;
    max_jobs: number | null;
    max_time: number | null;
    memory: number;
    env: EnvRow[];
    server_ids: string[];
}

interface Props extends SharedProps {
    workers: WorkerRow[];
    laravel: { available: boolean; horizon: boolean; octane: boolean; octane_port: number | null; horizon_program: string; octane_program: string };
    runsArtisan: boolean;
    containerRuntime: boolean;
}

interface WorkerForm {
    connection: string;
    queue: string;
    command: string;
    processes: string;
    timeout: string;
    sleep: string;
    tries: string;
    backoff: string;
    max_jobs: string;
    max_time: string;
    memory: string;
    env: EnvRow[];
    server_ids: string[];
}

const blank = (): WorkerForm => ({
    connection: '',
    queue: 'default',
    command: '',
    processes: '1',
    timeout: '60',
    sleep: '3',
    tries: '3',
    backoff: '',
    max_jobs: '',
    max_time: '3600',
    memory: '256',
    env: [],
    server_ids: [],
});

const fromWorker = (worker: WorkerRow): WorkerForm => ({
    connection: worker.connection ?? '',
    queue: worker.queue ?? '',
    command: worker.command ?? '',
    processes: String(worker.processes),
    timeout: String(worker.timeout),
    sleep: String(worker.sleep),
    tries: String(worker.tries),
    backoff: worker.backoff?.toString() ?? '',
    max_jobs: worker.max_jobs?.toString() ?? '',
    max_time: worker.max_time?.toString() ?? '',
    memory: String(worker.memory),
    env: worker.env,
    server_ids: worker.server_ids,
});

export default function Queues(props: Props) {
    const { site, servers, workers, laravel, runsArtisan, containerRuntime, can } = props;
    const [editing, setEditing] = useState<WorkerRow | 'new' | null>(null);

    const destroy = (worker: WorkerRow) => {
        if (window.confirm('Stop and remove this queue worker on every server?')) {
            router.delete(`/sites/${site.id}/queues/${worker.id}`, { preserveScroll: true });
        }
    };

    return (
        <SiteLayout
            site={site}
            title="Queues"
            actions={
                can.manage &&
                !containerRuntime && (
                    <Button size="sm" onClick={() => setEditing('new')}>
                        <Plus /> Add worker
                    </Button>
                )
            }
        >
            {containerRuntime && (
                <p className="text-muted-foreground text-sm">
                    Container sites run their workers inside their containers; define them in the image or compose file.
                </p>
            )}

            <ProcessStatusCard {...props} kinds={['horizon', 'octane', 'worker']} title="Status" />

            {laravel.available && (
                <Card>
                    <CardHeader>
                        <CardTitle>Laravel</CardTitle>
                        <CardDescription>
                            Horizon and Octane are switched on in the site{' '}
                            <Link href={`/sites/${site.id}/settings`} className="underline underline-offset-4">
                                settings
                            </Link>
                            ; Kiln supervises them on every server of the site.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3 text-sm sm:grid-cols-2">
                        <div className="flex items-center justify-between rounded-lg border p-3">
                            <div>
                                <div className="font-medium">Horizon</div>
                                <div className="text-muted-foreground font-mono text-xs">php artisan horizon</div>
                            </div>
                            <Badge variant={laravel.horizon ? 'secondary' : 'outline'}>{laravel.horizon ? 'on' : 'off'}</Badge>
                        </div>
                        <div className="flex items-center justify-between rounded-lg border p-3">
                            <div>
                                <div className="font-medium">Octane</div>
                                <div className="text-muted-foreground font-mono text-xs">
                                    php artisan octane:start{laravel.octane_port ? ` --port=${laravel.octane_port}` : ''}
                                </div>
                            </div>
                            <Badge variant={laravel.octane ? 'secondary' : 'outline'}>{laravel.octane ? 'on' : 'off'}</Badge>
                        </div>
                    </CardContent>
                </Card>
            )}

            <Card>
                <CardHeader>
                    <CardTitle>Queue workers</CardTitle>
                    <CardDescription>
                        {runsArtisan
                            ? 'Each worker runs php artisan queue:work as the site user in the current release.'
                            : 'Each worker runs its start command as the site user in the current release.'}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {workers.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No queue workers.</p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Worker</TableHead>
                                    <TableHead>Processes</TableHead>
                                    <TableHead>Limits</TableHead>
                                    <TableHead>Servers</TableHead>
                                    <TableHead className="w-24" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {workers.map((worker) => (
                                    <TableRow key={worker.id}>
                                        <TableCell>
                                            <div>{worker.label}</div>
                                            <div className="text-muted-foreground font-mono text-xs">{worker.program}</div>
                                        </TableCell>
                                        <TableCell>{worker.processes}</TableCell>
                                        <TableCell className="text-muted-foreground text-xs">
                                            {worker.command ? '—' : `timeout ${worker.timeout}s · tries ${worker.tries} · ${worker.memory} MB`}
                                        </TableCell>
                                        <TableCell className="text-xs">
                                            {worker.server_ids.length === 0
                                                ? 'all'
                                                : servers
                                                      .filter((s) => worker.server_ids.includes(s.id))
                                                      .map((s) => s.name)
                                                      .join(', ')}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {can.manage && (
                                                <div className="flex justify-end gap-1">
                                                    <Button variant="ghost" size="icon" onClick={() => setEditing(worker)} aria-label="Edit worker">
                                                        <Pencil className="size-4" />
                                                    </Button>
                                                    <Button variant="ghost" size="icon" onClick={() => destroy(worker)} aria-label="Remove worker">
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
                <WorkerDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    siteId={site.id}
                    worker={editing === 'new' ? null : editing}
                    servers={servers}
                    runsArtisan={runsArtisan}
                    onClose={() => setEditing(null)}
                />
            )}
        </SiteLayout>
    );
}

function WorkerDialog({
    siteId,
    worker,
    servers,
    runsArtisan,
    onClose,
}: {
    siteId: string;
    worker: WorkerRow | null;
    servers: SharedProps['servers'];
    runsArtisan: boolean;
    onClose: () => void;
}) {
    const form = useForm<WorkerForm>(worker ? fromWorker(worker) : blank());
    const [custom, setCustom] = useState(!runsArtisan || !!worker?.command);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            command: custom ? data.command : '',
            backoff: data.backoff === '' ? null : data.backoff,
            max_jobs: data.max_jobs === '' ? null : data.max_jobs,
            max_time: data.max_time === '' ? null : data.max_time,
            env: data.env.filter((row) => row.key.trim() !== ''),
        }));
        const options = { preserveScroll: true, onSuccess: onClose };

        if (worker) {
            form.put(`/sites/${siteId}/queues/${worker.id}`, options);
        } else {
            form.post(`/sites/${siteId}/queues`, options);
        }
    };

    const number = (field: keyof WorkerForm, label: string, hint?: string) => (
        <Field label={label} error={form.errors[field]} hint={hint}>
            <Input type="number" min={0} value={form.data[field] as string} onChange={(event) => form.setData(field, event.target.value)} />
        </Field>
    );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>{worker ? 'Edit queue worker' : 'Add queue worker'}</DialogTitle>
                        <DialogDescription>Workers restart after every deployment so they pick up the new code.</DialogDescription>
                    </DialogHeader>

                    {runsArtisan && (
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={custom} onCheckedChange={(checked) => setCustom(checked === true)} />
                            Use a custom command instead of queue:work
                        </label>
                    )}

                    {custom ? (
                        <Field label="Command" error={form.errors.command} hint="Runs with bash in the current release, e.g. bun run worker.">
                            <Input
                                value={form.data.command}
                                onChange={(event) => form.setData('command', event.target.value)}
                                className="font-mono"
                            />
                        </Field>
                    ) : (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Connection" error={form.errors.connection} hint="Empty = QUEUE_CONNECTION">
                                    <Input
                                        value={form.data.connection}
                                        onChange={(event) => form.setData('connection', event.target.value)}
                                        placeholder="redis"
                                    />
                                </Field>
                                <Field label="Queues" error={form.errors.queue} hint="Comma-separated, in priority order">
                                    <Input
                                        value={form.data.queue}
                                        onChange={(event) => form.setData('queue', event.target.value)}
                                        placeholder="high,default"
                                    />
                                </Field>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-3">
                                {number('timeout', 'Timeout (s)')}
                                {number('sleep', 'Sleep (s)')}
                                {number('tries', 'Tries', '0 = unlimited')}
                                {number('backoff', 'Backoff (s)')}
                                {number('max_jobs', 'Max jobs')}
                                {number('max_time', 'Max time (s)')}
                                {number('memory', 'Memory (MB)')}
                            </div>
                        </>
                    )}

                    <div className="grid gap-4 sm:grid-cols-3">{number('processes', 'Processes')}</div>

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
