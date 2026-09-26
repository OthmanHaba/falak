import { CommandLog, CommandStatusBadge, TERMINAL_COMMAND_STATUSES, type CommandStatus } from '@/components/command-log';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import SiteLayout, { type SiteHeader } from '@/layouts/site-layout';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { Loader2, Play } from 'lucide-react';
import { FormEventHandler, useEffect, useRef, useState } from 'react';
import { type SiteTarget } from '../types';

interface HistoryItem {
    id: string;
    command: string;
    server_id: string;
    server_name: string;
    unix_user: string;
    command_id: string | null;
    status: CommandStatus;
    exit_code: number | null;
    created_at: string;
    finished_at: string | null;
}

interface Props {
    site: SiteHeader;
    targets: SiteTarget[];
    commands: HistoryItem[];
    phpBinary: string | null;
    isLaravel: boolean;
    currentPath: string;
    can: { run: boolean };
}

const ARTISAN = ['migrate:status', 'optimize:clear', 'queue:restart', 'about', 'schedule:list'];

export default function Commands({ site, targets, commands, phpBinary, isLaravel, currentPath, can }: Props) {
    const leader = targets.find((target) => target.role === 'leader') ?? targets[0];
    const form = useForm({ server_id: leader?.server_id ?? '', command: '' });
    const [selected, setSelected] = useState<string | null>(commands[0]?.id ?? null);
    const newest = useRef(commands[0]?.id ?? null);

    // Follow the command that was just started.
    useEffect(() => {
        const first = commands[0]?.id ?? null;

        if (first && first !== newest.current) {
            newest.current = first;
            setSelected(first);
        }
    }, [commands]);

    const current = commands.find((command) => command.id === selected) ?? null;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(`/sites/${site.id}/commands`, { preserveScroll: true, onSuccess: () => form.setData('command', '') });
    };

    const onStatusChange = (status: CommandStatus) => {
        if (TERMINAL_COMMAND_STATUSES.includes(status)) {
            router.reload({ only: ['commands'] });
        }
    };

    return (
        <SiteLayout site={site} title="Commands">
            {can.run && (
                <Card>
                    <CardHeader>
                        <CardTitle>Run a command</CardTitle>
                        <CardDescription>
                            Runs in <code>{currentPath}</code> as the site user, with the KILN_* variables exported.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-3">
                            <div className="flex flex-col gap-2 sm:flex-row">
                                <Select value={form.data.server_id} onValueChange={(value) => form.setData('server_id', value)}>
                                    <SelectTrigger className="sm:w-52" aria-label="Server">
                                        <SelectValue placeholder="Server" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {targets.map((target) => (
                                            <SelectItem key={target.server_id} value={target.server_id}>
                                                {target.server_name}
                                                {target.role === 'leader' ? ' (leader)' : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <Input
                                    value={form.data.command}
                                    onChange={(e) => form.setData('command', e.target.value)}
                                    placeholder={phpBinary ? `${phpBinary} artisan about` : 'ls -la'}
                                    className="font-mono"
                                    aria-label="Command"
                                />
                                <Button type="submit" disabled={form.processing || form.data.command.trim() === ''}>
                                    {form.processing ? <Loader2 className="animate-spin" /> : <Play />} Run
                                </Button>
                            </div>
                            <InputError message={form.errors.command ?? form.errors.server_id} />
                            {isLaravel && phpBinary && (
                                <div className="flex flex-wrap gap-1.5">
                                    {ARTISAN.map((command) => (
                                        <Button
                                            key={command}
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="h-7 font-mono text-xs"
                                            onClick={() => form.setData('command', `${phpBinary} artisan ${command}`)}
                                        >
                                            artisan {command}
                                        </Button>
                                    ))}
                                </div>
                            )}
                        </form>
                    </CardContent>
                </Card>
            )}

            <div className="grid gap-6 lg:grid-cols-5">
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>History</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-1 px-2">
                        {commands.length === 0 && <p className="text-muted-foreground px-2 text-sm">No commands yet.</p>}
                        {commands.map((command) => (
                            <button
                                type="button"
                                key={command.id}
                                onClick={() => setSelected(command.id)}
                                className={cn('hover:bg-muted w-full rounded-md px-2 py-2 text-left', selected === command.id && 'bg-muted')}
                            >
                                <div className="flex items-center gap-2">
                                    <code className="min-w-0 flex-1 truncate text-xs">{command.command}</code>
                                    <CommandStatusBadge status={command.status} />
                                </div>
                                <div className="text-muted-foreground mt-1 text-xs">
                                    {command.server_name} · {formatDistanceToNow(new Date(command.created_at), { addSuffix: true })}
                                    {command.exit_code !== null && ` · exit ${command.exit_code}`}
                                </div>
                            </button>
                        ))}
                    </CardContent>
                </Card>

                <div className="lg:col-span-3">
                    {current?.command_id ? (
                        <div className="space-y-2">
                            <p className="font-mono text-sm break-all">
                                <span className="text-muted-foreground">
                                    {current.unix_user}@{current.server_name}$
                                </span>{' '}
                                {current.command}
                            </p>
                            <CommandLog commandId={current.command_id} onStatusChange={onStatusChange} />
                        </div>
                    ) : (
                        <p className="text-muted-foreground text-sm">Select a command to see its output.</p>
                    )}
                </div>
            </div>
        </SiteLayout>
    );
}
