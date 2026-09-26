import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Link, router } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { AlertTriangle, Loader2, RefreshCw, RotateCw, ScrollText } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { type ProcessInstance, type ProcessServer, type ProgramStatus, type SharedProps } from '../types';
import { ApplyBadge, StateBadge } from './process-ui';

interface LiveResult {
    id: string;
    server_id: string;
    terminal: boolean;
    error: string | null;
    processes: (ProcessInstance & { name: string })[];
}

function csrf(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

async function request<T>(url: string, method: 'GET' | 'POST'): Promise<T | null> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() },
    });

    return response.ok ? ((await response.json()) as T) : null;
}

/**
 * Programs of the site with their last known state per server; "Refresh" asks the agents (proc.status) live.
 */
export function ProcessStatusCard({
    site,
    servers,
    programs,
    logsUrl,
    can,
    kinds,
    title,
}: SharedProps & { kinds: ProgramStatus['kind'][]; title: string }) {
    const [live, setLive] = useState<Record<string, LiveResult>>({});
    const [refreshing, setRefreshing] = useState(false);
    const [restarting, setRestarting] = useState(false);
    const timer = useRef<number | null>(null);

    useEffect(() => () => void (timer.current && window.clearTimeout(timer.current)), []);

    const shown = programs.filter((program) => kinds.includes(program.kind));
    const byServer = new Map<string, ProgramStatus[]>();
    shown.forEach((program) => byServer.set(program.server_id, [...(byServer.get(program.server_id) ?? []), program]));

    const poll = (ids: string[], attempt: number) => {
        void request<{ results: LiveResult[] }>(`/sites/${site.id}/processes/status?commands=${ids.join(',')}`, 'GET').then((body) => {
            const results = body?.results ?? [];
            setLive((current) => ({ ...current, ...Object.fromEntries(results.filter((r) => r.terminal).map((r) => [r.server_id, r])) }));

            if (results.every((r) => r.terminal) || attempt >= 20) {
                setRefreshing(false);
                return;
            }

            timer.current = window.setTimeout(() => poll(ids, attempt + 1), 1500);
        });
    };

    const refresh = () => {
        setRefreshing(true);
        void request<{ commands: { id: string; server_id: string }[] }>(`/sites/${site.id}/processes/status`, 'POST').then((body) => {
            const ids = body?.commands.map((command) => command.id) ?? [];

            if (ids.length === 0) {
                setRefreshing(false);
                return;
            }

            timer.current = window.setTimeout(() => poll(ids, 0), 1000);
        });
    };

    const restart = (serverId?: string) => {
        if (!window.confirm('Gracefully restart the processes of this site?')) return;

        setRestarting(true);
        router.post(`/sites/${site.id}/processes/restart`, serverId ? { server_id: serverId } : {}, {
            preserveScroll: true,
            onFinish: () => setRestarting(false),
        });
    };

    const instancesOf = (program: ProgramStatus): ProcessInstance[] => {
        const result = live[program.server_id];

        return result ? result.processes.filter((p) => p.name === program.name).sort((a, b) => a.instance - b.instance) : program.instances;
    };

    return (
        <Card>
            <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-2 space-y-0">
                <div className="space-y-1.5">
                    <CardTitle>{title}</CardTitle>
                    <CardDescription>Supervised by the Kiln agent on each server. Output goes to the site logs.</CardDescription>
                </div>
                <div className="flex flex-wrap gap-2">
                    {logsUrl && (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={logsUrl}>
                                <ScrollText /> Logs
                            </Link>
                        </Button>
                    )}
                    <Button variant="outline" size="sm" onClick={refresh} disabled={refreshing}>
                        {refreshing ? <Loader2 className="animate-spin" /> : <RefreshCw />} Refresh status
                    </Button>
                    {can.manage && (
                        <Button variant="outline" size="sm" onClick={() => restart()} disabled={restarting || shown.length === 0}>
                            <RotateCw /> Restart
                        </Button>
                    )}
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                {servers.length === 0 && <p className="text-muted-foreground text-sm">The site has no servers.</p>}
                {servers.map((server) => (
                    <ServerPrograms
                        key={server.id}
                        server={server}
                        programs={byServer.get(server.id) ?? []}
                        instancesOf={instancesOf}
                        live={!!live[server.id]}
                    />
                ))}
            </CardContent>
        </Card>
    );
}

function ServerPrograms({
    server,
    programs,
    instancesOf,
    live,
}: {
    server: ProcessServer;
    programs: ProgramStatus[];
    instancesOf: (program: ProgramStatus) => ProcessInstance[];
    live: boolean;
}) {
    return (
        <div className="space-y-2 rounded-lg border p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2 text-sm font-medium">
                    {server.name}
                    {server.role === 'leader' && <span className="text-muted-foreground text-xs font-normal">leader</span>}
                    <ApplyBadge status={server.proc.status} error={server.proc.error} />
                </div>
                <div className="text-muted-foreground flex items-center gap-3 text-xs">
                    {live
                        ? 'live'
                        : server.status_at
                          ? `checked ${formatDistanceToNow(new Date(server.status_at), { addSuffix: true })}`
                          : 'status not checked yet'}
                    {server.logs_url && (
                        <Link href={server.logs_url} className="underline-offset-4 hover:underline">
                            logs
                        </Link>
                    )}
                </div>
            </div>
            {server.proc.error && (server.proc.status === 'failed' || server.proc.status === 'error') && (
                <p className="text-destructive text-sm">{server.proc.error}</p>
            )}
            {server.target_status !== 'ready' && <p className="text-muted-foreground text-sm">The site is still being prepared on this server.</p>}
            {programs.length === 0 ? (
                <p className="text-muted-foreground text-sm">Nothing runs here.</p>
            ) : (
                <ul className="divide-y">
                    {programs.map((program) => {
                        const instances = instancesOf(program);

                        return (
                            <li key={program.name} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                <div className="min-w-0">
                                    <div className="flex items-center gap-2 text-sm">
                                        {program.label}
                                        {program.crash_looping && (
                                            <span className="text-destructive flex items-center gap-1 text-xs">
                                                <AlertTriangle className="size-3.5" /> crash-looping
                                            </span>
                                        )}
                                    </div>
                                    <div className="text-muted-foreground font-mono text-xs">
                                        {program.name} · {program.numprocs} process{program.numprocs === 1 ? '' : 'es'}
                                        {!program.applied && ' · not applied yet'}
                                    </div>
                                </div>
                                <div className="flex flex-wrap items-center gap-1">
                                    {instances.length === 0 ? (
                                        <span className="text-muted-foreground text-xs">—</span>
                                    ) : (
                                        instances.map((instance) => (
                                            <span
                                                key={instance.instance}
                                                title={[
                                                    instance.pid ? `pid ${instance.pid}` : null,
                                                    instance.restarts !== null ? `${instance.restarts} restarts` : null,
                                                    instance.last_exit_code !== null ? `last exit ${instance.last_exit_code}` : null,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            >
                                                <StateBadge state={instance.state} />
                                            </span>
                                        ))
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
