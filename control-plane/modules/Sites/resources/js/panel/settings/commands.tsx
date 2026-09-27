import { CommandLog, CommandStatusBadge, TERMINAL_COMMAND_STATUSES, type CommandStatus } from '@/components/command-log';
import { Button, Callout, EmptyState, Input, RelativeTime, Section, Select, SkeletonRows, toast } from '@/components/kiln';
import { useJson } from '@/hooks/use-json';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { type ServiceTabProps } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { Play, Terminal } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { type SiteTarget } from '../../types';

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

/** GET /sites/{site}/commands (JSON). */
interface CommandsData {
    targets: SiteTarget[];
    commands: HistoryItem[];
    phpBinary: string | null;
    isLaravel: boolean;
    currentPath: string;
    can: { run: boolean };
}

const ARTISAN = ['migrate:status', 'optimize:clear', 'queue:restart', 'about', 'schedule:list'];

/** Run an artisan / shell command in the current release on one server, with live output and history. */
export function CommandsSettings({ ctx }: ServiceTabProps) {
    const url = `/sites/${ctx.service.ref_id}/commands`;
    const [following, setFollowing] = useState(false);
    const { data, error, reload } = useJson<CommandsData>(url, { interval: following ? 3000 : false });
    const [server, setServer] = useState<string | null>(null);
    const [command, setCommand] = useState('');
    const [running, setRunning] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const [selected, setSelected] = useState<string | null>(null);

    if (!data) return error ? <Callout tone="danger">{error}</Callout> : <SkeletonRows rows={4} />;

    const leader = data.targets.find((target) => target.role === 'leader') ?? data.targets[0];
    const serverId = server ?? leader?.server_id ?? '';
    const current = data.commands.find((item) => item.id === (selected ?? data.commands[0]?.id)) ?? null;
    const prefix = data.isLaravel && data.phpBinary ? `${data.phpBinary} artisan ` : '';

    const run = async (event: FormEvent) => {
        event.preventDefault();
        if (!command.trim()) return;
        setRunning(true);
        setProblem(null);
        try {
            const body = await requestJson<{ data: { id: string } }>(url, 'POST', { server_id: serverId || null, command });
            setCommand('');
            setFollowing(true);
            await reload();
            setSelected(body.data.id);
        } catch (e) {
            setProblem(e instanceof HttpError ? (Object.values(e.errors)[0] ?? e.message) : errorMessage(e));
            if (!(e instanceof HttpError)) toast.error('Could not run the command', errorMessage(e));
        } finally {
            setRunning(false);
        }
    };

    return (
        <Section
            title="Commands"
            description={
                <>
                    Runs in <code className="font-mono text-xs">{data.currentPath}</code> as the site user. Every run is audited.
                </>
            }
        >
            {data.can.run && data.targets.length > 0 && (
                <form onSubmit={run} className="grid gap-2">
                    <div className="flex flex-wrap gap-2">
                        {data.targets.length > 1 && (
                            <Select
                                className="w-36"
                                aria-label="Server"
                                value={serverId}
                                onValueChange={setServer}
                                options={data.targets.map((target) => ({
                                    value: target.server_id,
                                    label: `${target.server_name}${target.role === 'leader' ? ' ★' : ''}`,
                                }))}
                            />
                        )}
                        <div className="min-w-0 flex-1">
                            <Input
                                mono
                                aria-label="Command"
                                prefix={<Terminal />}
                                placeholder={prefix ? `${prefix}migrate:status` : 'ls -la storage'}
                                value={command}
                                aria-invalid={problem ? true : undefined}
                                onChange={(event) => setCommand(event.target.value)}
                                onKeyDown={(event) => event.stopPropagation()}
                            />
                        </div>
                        <Button type="submit" variant="primary" icon={<Play />} loading={running} disabled={!command.trim()}>
                            Run
                        </Button>
                    </div>
                    {problem && <p className="text-danger text-xs">{problem}</p>}
                    {prefix && (
                        <div className="flex flex-wrap gap-1.5">
                            {ARTISAN.map((item) => (
                                <button
                                    key={item}
                                    type="button"
                                    onClick={() => setCommand(`${prefix}${item}`)}
                                    className="border-border bg-surface-2 text-fg-muted hover:text-fg rounded-sm border px-1.5 py-0.5 font-mono text-[11px]"
                                >
                                    {item}
                                </button>
                            ))}
                        </div>
                    )}
                </form>
            )}

            {data.commands.length === 0 ? (
                <EmptyState size="sm" icon={<Terminal />} title="No commands yet" description="Output of every run is kept here." />
            ) : (
                <div className="grid gap-3 lg:grid-cols-[minmax(0,15rem)_minmax(0,1fr)]">
                    <ul className="border-border divide-border max-h-80 divide-y overflow-y-auto rounded-md border" aria-label="Command history">
                        {data.commands.map((item) => (
                            <li key={item.id}>
                                <button
                                    type="button"
                                    onClick={() => setSelected(item.id)}
                                    aria-current={current?.id === item.id || undefined}
                                    className={cn(
                                        'grid w-full gap-1 px-3 py-2 text-left',
                                        current?.id === item.id ? 'bg-surface-3' : 'hover:bg-surface-2',
                                    )}
                                >
                                    <span className="text-fg truncate font-mono text-xs">{item.command}</span>
                                    <span className="text-fg-faint flex items-center gap-2 text-[11px]">
                                        <CommandStatusBadge status={item.status} />
                                        {item.server_name} · <RelativeTime value={item.created_at} />
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                    <div className="min-w-0">
                        {current?.command_id ? (
                            <CommandLog
                                key={current.command_id}
                                commandId={current.command_id}
                                onStatusChange={(status) => {
                                    if (TERMINAL_COMMAND_STATUSES.includes(status)) {
                                        setFollowing(false);
                                        void reload();
                                    }
                                }}
                            />
                        ) : (
                            <p className="text-fg-muted text-sm">{current ? 'Waiting for the agent…' : 'Pick a command to see its output.'}</p>
                        )}
                    </div>
                </div>
            )}
        </Section>
    );
}
