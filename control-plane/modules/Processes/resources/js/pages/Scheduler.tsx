import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import SiteLayout from '@/layouts/site-layout';
import { Link, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { ApplyBadge, Field } from '../components/process-ui';
import { type SharedProps } from '../types';

interface ScheduleRow {
    id: string;
    job: string;
    name: string;
    command: string;
    expression: string;
    timezone: string;
    user: string | null;
    overlap: 'allow' | 'skip';
    timeout: number;
    heartbeat: boolean;
    enabled: boolean;
    all_servers: boolean;
    deployed: boolean;
}

interface Props extends SharedProps {
    scheduler: { available: boolean; enabled: boolean; job: string; command: string; deployed: boolean };
    leader: string | null;
    schedules: ScheduleRow[];
    defaults: { user: string; cwd: string; php: string | null };
    presets: { label: string; value: string }[];
    timezones: string[];
    insightsUrl: string;
    containerRuntime: boolean;
}

interface ScheduleForm {
    name: string;
    command: string;
    expression: string;
    timezone: string;
    user: string;
    overlap: 'allow' | 'skip';
    timeout: string;
    heartbeat: boolean;
    enabled: boolean;
    all_servers: boolean;
}

export default function Scheduler({
    site,
    servers,
    scheduler,
    leader,
    schedules,
    defaults,
    presets,
    timezones,
    insightsUrl,
    containerRuntime,
    can,
}: Props) {
    const [editing, setEditing] = useState<ScheduleRow | 'new' | null>(null);
    const leaderServer = servers.find((server) => server.id === leader);

    const destroy = (schedule: ScheduleRow) => {
        if (window.confirm(`Remove the scheduled job ${schedule.name}?`)) {
            router.delete(`/sites/${site.id}/scheduler/${schedule.id}`, { preserveScroll: true });
        }
    };

    return (
        <SiteLayout
            site={site}
            title="Scheduler"
            actions={
                can.manage &&
                !containerRuntime && (
                    <Button size="sm" onClick={() => setEditing('new')}>
                        <Plus /> Add scheduled job
                    </Button>
                )
            }
        >
            <Card>
                <CardHeader>
                    <CardTitle>Schedule</CardTitle>
                    <CardDescription>
                        Jobs run on {leaderServer ? <strong>{leaderServer.name}</strong> : 'the leader server'} by the agent's built-in scheduler.
                        Every run reports a heartbeat; missed and failed runs open issues in{' '}
                        <Link href={insightsUrl} className="underline underline-offset-4">
                            Insights
                        </Link>
                        .
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                    {servers.map((server) => (
                        <div key={server.id} className="flex flex-wrap items-center gap-2 text-sm">
                            <span className="font-medium">{server.name}</span>
                            <ApplyBadge status={server.cron.status} error={server.cron.error} />
                            {server.cron.error && (server.cron.status === 'failed' || server.cron.status === 'error') && (
                                <span className="text-destructive text-xs">{server.cron.error}</span>
                            )}
                        </div>
                    ))}
                </CardContent>
            </Card>

            {scheduler.available && (
                <Card>
                    <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-2 space-y-0">
                        <div className="space-y-1.5">
                            <CardTitle>Laravel scheduler</CardTitle>
                            <CardDescription>
                                <code>{scheduler.command}</code> every minute on the leader. Toggle it in the site{' '}
                                <Link href={`/sites/${site.id}/settings`} className="underline underline-offset-4">
                                    settings
                                </Link>
                                .
                            </CardDescription>
                        </div>
                        <Badge variant={scheduler.enabled ? 'secondary' : 'outline'}>
                            {scheduler.enabled ? (scheduler.deployed ? 'on' : 'on · applying') : 'off'}
                        </Badge>
                    </CardHeader>
                </Card>
            )}

            <Card>
                <CardHeader>
                    <CardTitle>Scheduled jobs</CardTitle>
                    <CardDescription>
                        Commands run with sh in <code>{defaults.cwd}</code>.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {schedules.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No scheduled jobs.</p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Job</TableHead>
                                    <TableHead>Schedule</TableHead>
                                    <TableHead>Runs on</TableHead>
                                    <TableHead>State</TableHead>
                                    <TableHead className="w-24" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {schedules.map((schedule) => (
                                    <TableRow key={schedule.id}>
                                        <TableCell className="max-w-md">
                                            <div>{schedule.name}</div>
                                            <div className="text-muted-foreground truncate font-mono text-xs" title={schedule.command}>
                                                {schedule.command}
                                            </div>
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {schedule.expression}
                                            {schedule.timezone !== 'UTC' && <span className="text-muted-foreground"> ({schedule.timezone})</span>}
                                        </TableCell>
                                        <TableCell className="text-xs">{schedule.all_servers ? 'every server' : 'leader'}</TableCell>
                                        <TableCell>
                                            {!schedule.enabled ? (
                                                <Badge variant="outline">paused</Badge>
                                            ) : (
                                                <Badge variant="secondary">{schedule.deployed ? 'active' : 'applying'}</Badge>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {can.manage && (
                                                <div className="flex justify-end gap-1">
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        onClick={() => setEditing(schedule)}
                                                        aria-label={`Edit ${schedule.name}`}
                                                    >
                                                        <Pencil className="size-4" />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        onClick={() => destroy(schedule)}
                                                        aria-label={`Remove ${schedule.name}`}
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
                <ScheduleDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    siteId={site.id}
                    schedule={editing === 'new' ? null : editing}
                    defaults={defaults}
                    presets={presets}
                    timezones={timezones}
                    multipleServers={servers.length > 1}
                    onClose={() => setEditing(null)}
                />
            )}
        </SiteLayout>
    );
}

function ScheduleDialog({
    siteId,
    schedule,
    defaults,
    presets,
    timezones,
    multipleServers,
    onClose,
}: {
    siteId: string;
    schedule: ScheduleRow | null;
    defaults: Props['defaults'];
    presets: Props['presets'];
    timezones: string[];
    multipleServers: boolean;
    onClose: () => void;
}) {
    const form = useForm<ScheduleForm>({
        name: schedule?.name ?? '',
        command: schedule?.command ?? '',
        expression: schedule?.expression ?? '@daily',
        timezone: schedule?.timezone ?? 'UTC',
        user: schedule?.user ?? '',
        overlap: schedule?.overlap ?? 'skip',
        timeout: String(schedule?.timeout ?? 3600),
        heartbeat: schedule?.heartbeat ?? true,
        enabled: schedule?.enabled ?? true,
        all_servers: schedule?.all_servers ?? false,
    });
    const preset = presets.find((p) => p.value === form.data.expression)?.value ?? 'custom';

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };

        if (schedule) {
            form.put(`/sites/${siteId}/scheduler/${schedule.id}`, opts);
        } else {
            form.post(`/sites/${siteId}/scheduler`, opts);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>{schedule ? `Edit ${schedule.name}` : 'Add scheduled job'}</DialogTitle>
                        <DialogDescription>Runs as {defaults.user} unless another user is set.</DialogDescription>
                    </DialogHeader>

                    <Field label="Name" error={form.errors.name}>
                        <Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} placeholder="Nightly report" />
                    </Field>
                    <Field label="Command" error={form.errors.command}>
                        <Textarea
                            value={form.data.command}
                            onChange={(event) => form.setData('command', event.target.value)}
                            className="font-mono"
                            rows={2}
                            placeholder={defaults.php ? `${defaults.php} artisan reports:send` : './bin/report'}
                        />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Frequency">
                            <Select value={preset} onValueChange={(value) => value !== 'custom' && form.setData('expression', value)}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {presets.map((p) => (
                                        <SelectItem key={p.value} value={p.value}>
                                            {p.label}
                                        </SelectItem>
                                    ))}
                                    <SelectItem value="custom">Custom</SelectItem>
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field label="Cron expression" error={form.errors.expression} hint='5 fields, a preset like @hourly, or "@every 30s"'>
                            <Input
                                value={form.data.expression}
                                onChange={(event) => form.setData('expression', event.target.value)}
                                className="font-mono"
                            />
                        </Field>
                        <Field label="Timezone" error={form.errors.timezone}>
                            <Select value={form.data.timezone} onValueChange={(value) => form.setData('timezone', value)}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent className="max-h-72">
                                    {timezones.map((zone) => (
                                        <SelectItem key={zone} value={zone}>
                                            {zone}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field label="User" error={form.errors.user} hint={`Default: ${defaults.user}`}>
                            <Input
                                value={form.data.user}
                                onChange={(event) => form.setData('user', event.target.value)}
                                placeholder={defaults.user}
                            />
                        </Field>
                        <Field label="Timeout (s)" error={form.errors.timeout}>
                            <Input
                                type="number"
                                min={1}
                                value={form.data.timeout}
                                onChange={(event) => form.setData('timeout', event.target.value)}
                            />
                        </Field>
                        <Field label="If still running" error={form.errors.overlap}>
                            <Select value={form.data.overlap} onValueChange={(value) => form.setData('overlap', value as 'allow' | 'skip')}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="skip">Skip the next run</SelectItem>
                                    <SelectItem value="allow">Start another run</SelectItem>
                                </SelectContent>
                            </Select>
                        </Field>
                    </div>
                    <div className="space-y-2 text-sm">
                        <label className="flex items-center gap-2">
                            <Checkbox checked={form.data.heartbeat} onCheckedChange={(checked) => form.setData('heartbeat', checked === true)} />
                            Report heartbeats (alert on missed or failed runs)
                        </label>
                        <label className="flex items-center gap-2">
                            <Checkbox checked={form.data.enabled} onCheckedChange={(checked) => form.setData('enabled', checked === true)} />
                            Enabled
                        </label>
                        {multipleServers && (
                            <label className="flex items-center gap-2">
                                <Checkbox
                                    checked={form.data.all_servers}
                                    onCheckedChange={(checked) => form.setData('all_servers', checked === true)}
                                />
                                Run on every server of the site (not only the leader)
                            </label>
                        )}
                    </div>

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
