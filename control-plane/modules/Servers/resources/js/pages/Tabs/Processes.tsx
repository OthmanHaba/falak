import { Button } from '@/components/falak/button';
import { DataTable } from '@/components/falak/data-table';
import { EmptyState } from '@/components/falak/empty-state';
import { RelativeTime } from '@/components/falak/relative-time';
import { ServiceIcon } from '@/components/falak/service-icon';
import { StatusBadge } from '@/components/falak/status';
import { toast } from '@/components/falak/toast';
import ServerLayout, { type ServerHeader } from '@/layouts/server-layout';
import { Link, router } from '@inertiajs/react';
import { Cpu, Lock, RefreshCw, RotateCw, WifiOff } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { requestJson } from '../../components/server-ui';

interface SiteRef {
    id: string;
    name: string;
    icon: string;
    url: string;
}

interface Props {
    server: ServerHeader;
    sites: SiteRef[];
    can: { view: boolean; manage: boolean };
}

interface ProcessRow {
    key: string;
    name: string;
    site: SiteRef;
    instance: number;
    state: string;
    pid: number | null;
    restarts: number | null;
    started_at: string | null;
    last_exit_code: number | null;
}

interface StatusResult {
    id: string;
    server_id: string;
    terminal: boolean;
    status: string;
    error: string | null;
    processes: {
        name: string;
        instance?: number;
        state: string;
        pid: number | null;
        restarts: number | null;
        started_at: string | null;
        last_exit_code: number | null;
    }[];
}

const STATE: Record<string, { status: string; label: string }> = {
    running: { status: 'active', label: 'Running' },
    starting: { status: 'running', label: 'Starting' },
    stopping: { status: 'running', label: 'Stopping' },
    backoff: { status: 'degraded', label: 'Backoff' },
    stopped: { status: 'inactive', label: 'Stopped' },
    exited: { status: 'inactive', label: 'Exited' },
    fatal: { status: 'crashed', label: 'Fatal' },
    unknown: { status: 'inactive', label: 'Unknown' },
};

type Phase = 'idle' | 'loading' | 'done' | 'timeout' | 'error';

/**
 * Live supervisor state (proc.status) of every site program on this server, gathered through each site's
 * Processes endpoints and filtered to this server.
 */
export default function Processes({ server, sites, can }: Props) {
    const [rows, setRows] = useState<ProcessRow[]>([]);
    const [phase, setPhase] = useState<Phase>('idle');
    const [checkedAt, setCheckedAt] = useState<string | null>(null);
    const [restarting, setRestarting] = useState<string | null>(null);
    const timer = useRef<number | undefined>(undefined);
    const online = server.agent?.status === 'online';

    const refresh = useCallback(async () => {
        window.clearTimeout(timer.current);
        setPhase('loading');

        try {
            const requested = await Promise.all(
                sites.map(async (site) => {
                    const body = await requestJson<{ commands: { id: string; server_id: string }[] }>(`/sites/${site.id}/processes/status`, {
                        method: 'POST',
                    });

                    return { site, ids: body.commands.filter((command) => command.server_id === server.id).map((command) => command.id) };
                }),
            );
            const pending = requested.filter((item) => item.ids.length > 0);

            if (pending.length === 0) {
                setRows([]);
                setPhase('done');
                setCheckedAt(new Date().toISOString());

                return;
            }

            const poll = async (attempt: number) => {
                const results = await Promise.all(
                    pending.map(async ({ site, ids }) => {
                        const body = await requestJson<{ results: StatusResult[] }>(`/sites/${site.id}/processes/status?commands=${ids.join(',')}`);

                        return { site, results: body.results.filter((result) => result.server_id === server.id) };
                    }),
                );
                const settled = results.every(({ results: list }) => list.length > 0 && list.every((result) => result.terminal));

                if (settled || attempt >= 15) {
                    setRows(
                        results.flatMap(({ site, results: list }) =>
                            list.flatMap((result) =>
                                result.processes.map((process, index) => ({
                                    key: `${site.id}-${process.name}-${process.instance ?? index}`,
                                    name: process.name,
                                    site,
                                    instance: process.instance ?? index,
                                    state: process.state,
                                    pid: process.pid,
                                    restarts: process.restarts,
                                    started_at: process.started_at,
                                    last_exit_code: process.last_exit_code,
                                })),
                            ),
                        ),
                    );
                    setPhase(settled ? 'done' : 'timeout');
                    setCheckedAt(new Date().toISOString());

                    return;
                }

                timer.current = window.setTimeout(() => void poll(attempt + 1), 1500);
            };

            timer.current = window.setTimeout(() => void poll(0), 800);
        } catch {
            setPhase('error');
        }
    }, [sites, server.id]);

    useEffect(() => {
        if (can.view && online && sites.length > 0) void refresh();

        return () => window.clearTimeout(timer.current);
    }, [can.view, online, sites.length, refresh]);

    const restart = (site: SiteRef) =>
        router.post(
            `/sites/${site.id}/processes/restart`,
            { server_id: server.id },
            {
                preserveScroll: true,
                onStart: () => setRestarting(site.id),
                onFinish: () => setRestarting(null),
                onSuccess: () => window.setTimeout(() => void refresh(), 2000),
                onError: () => toast.error(`Could not restart ${site.name}'s processes`),
            },
        );

    const body = () => {
        if (!can.view) {
            return <EmptyState icon={<Lock />} title="No access to processes" description="Ask an admin for the processes.view permission." />;
        }
        if (sites.length === 0) {
            return (
                <EmptyState
                    icon={<Cpu />}
                    title="No supervised processes"
                    description="Queue workers, Horizon, Octane and daemons of sites deployed to this server appear here with their live state."
                />
            );
        }
        if (!online) {
            return (
                <EmptyState
                    icon={<WifiOff />}
                    title="Agent offline"
                    description="Live process state is read from the agent. It shows up again once the server reconnects."
                />
            );
        }

        return (
            <DataTable
                label="Processes"
                rows={rows}
                rowKey={(row) => row.key}
                loading={phase === 'loading' && rows.length === 0}
                defaultSort={{ column: 'site', direction: 'asc' }}
                empty={{
                    icon: <Cpu />,
                    title:
                        phase === 'timeout'
                            ? 'The agent did not answer in time'
                            : phase === 'error'
                              ? 'Could not read process state'
                              : 'Nothing is running',
                    description:
                        phase === 'timeout' || phase === 'error'
                            ? 'Try again in a moment.'
                            : 'The sites on this server have no queue workers, daemons or long-running web processes.',
                    action:
                        phase === 'timeout' || phase === 'error' ? (
                            <Button size="sm" icon={<RefreshCw />} onClick={() => void refresh()}>
                                Retry
                            </Button>
                        ) : undefined,
                    size: 'sm',
                }}
                columns={[
                    {
                        id: 'name',
                        header: 'Program',
                        sortValue: (row) => row.name,
                        cell: (row) => (
                            <span className="font-mono text-xs">
                                {row.name}
                                {row.instance > 0 && <span className="text-fg-faint">:{row.instance}</span>}
                            </span>
                        ),
                    },
                    {
                        id: 'site',
                        header: 'Service',
                        sortValue: (row) => row.site.name,
                        cell: (row) => (
                            <Link href={row.site.url} className="text-fg flex items-center gap-2 hover:underline">
                                <ServiceIcon name={row.site.icon} size={14} />
                                {row.site.name}
                            </Link>
                        ),
                    },
                    {
                        id: 'state',
                        header: 'State',
                        sortValue: (row) => row.state,
                        cell: (row) => {
                            const spec = STATE[row.state] ?? STATE.unknown;

                            return <StatusBadge status={spec.status} label={spec.label} />;
                        },
                    },
                    { id: 'pid', header: 'PID', align: 'right', hideOnMobile: true, sortValue: (row) => row.pid, cell: (row) => row.pid ?? '—' },
                    {
                        id: 'restarts',
                        header: 'Restarts',
                        align: 'right',
                        hideOnMobile: true,
                        sortValue: (row) => row.restarts,
                        cell: (row) => row.restarts ?? '—',
                    },
                    {
                        id: 'started',
                        header: 'Started',
                        align: 'right',
                        hideOnMobile: true,
                        sortValue: (row) => row.started_at,
                        cell: (row) => <RelativeTime value={row.started_at} className="text-fg-muted text-xs" />,
                    },
                ]}
                rowActions={
                    can.manage
                        ? (row) => [
                              {
                                  label: restarting === row.site.id ? 'Restarting…' : `Restart ${row.site.name} processes`,
                                  icon: <RotateCw />,
                                  disabled: restarting !== null,
                                  onSelect: () => restart(row.site),
                              },
                          ]
                        : undefined
                }
            />
        );
    };

    return (
        <ServerLayout
            server={server}
            tab="processes"
            actions={
                can.view &&
                online &&
                sites.length > 0 && (
                    <>
                        {checkedAt && phase !== 'loading' && (
                            <span className="text-fg-faint text-xs">
                                Checked <RelativeTime value={checkedAt} />
                            </span>
                        )}
                        <Button icon={<RefreshCw />} onClick={() => void refresh()} loading={phase === 'loading'}>
                            Refresh
                        </Button>
                    </>
                )
            }
        >
            {body()}
        </ServerLayout>
    );
}
