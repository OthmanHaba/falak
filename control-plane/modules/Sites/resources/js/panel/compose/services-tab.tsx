import { Button, Callout, CopyButton, EmptyState, IconButton, RelativeTime, SkeletonRows, StatusBadge, Tag, Tooltip, toast } from '@/components/kiln';
import { useJson } from '@/hooks/use-json';
import { errorMessage, requestJson } from '@/lib/http';
import { type ServiceTabProps } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { Boxes, ExternalLink, RefreshCw, RotateCw, ScrollText } from 'lucide-react';
import { useState } from 'react';
import { deployNow } from '../api';
import { composeUrl, containerStatus, formatBytes, shortImage, type ServiceRow, type ServicesData } from './api';

/**
 * Compose sites → Services (docs/COMPOSE_TEMPLATES.md §1.6): one row per compose service (and server): state and
 * health, image (short digest), ports / public URL, restarts, CPU / memory, restart and logs actions.
 */
export function ServicesTab({ ctx }: ServiceTabProps) {
    const siteId = ctx.service.ref_id;
    const url = `${composeUrl(siteId)}/services`;
    const { data, error, reload } = useJson<ServicesData>(url, { interval: 5000 });
    const [restarting, setRestarting] = useState<string | null>(null);
    const [refreshing, setRefreshing] = useState(false);

    if (!data) return error ? <Callout tone="danger">{error}</Callout> : <SkeletonRows rows={5} />;

    const multiServer = data.servers.length > 1;
    const refresh = async () => {
        setRefreshing(true);
        try {
            await requestJson(`${url}?refresh=1`);
            window.setTimeout(() => void reload().finally(() => setRefreshing(false)), 1500);
        } catch (e) {
            setRefreshing(false);
            toast.error('Could not refresh', errorMessage(e));
        }
    };

    const restart = async (service: string | null, serverId: string | null = null) => {
        const key = `${service ?? '*'}:${serverId ?? '*'}`;
        setRestarting(key);
        try {
            await requestJson(`${composeUrl(siteId)}/restart`, 'POST', { service, server_id: serverId });
            toast.success(
                service ? `Restarting ${service}` : 'Restarting every service',
                multiServer && !serverId ? 'On every server of the site' : undefined,
            );
            window.setTimeout(() => void reload(), 2500);
        } catch (e) {
            toast.error('Could not restart', errorMessage(e));
        } finally {
            setRestarting(null);
        }
    };

    const unhealthy = data.services.filter((row) => ['crashed', 'failed'].includes(containerStatus(row).status));
    const reported = data.servers.map((server) => server.reported_at).filter((at): at is string => at !== null);
    const lastReport = reported.sort().at(-1) ?? null;

    return (
        <div className="grid gap-4" data-testid="services-tab">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="text-fg-muted flex flex-wrap items-center gap-2 text-xs">
                    <span className="text-fg text-sm font-medium">{new Set(data.services.map((row) => row.service)).size} services</span>
                    {unhealthy.length > 0 && <Tag tone="danger">{unhealthy.length} down</Tag>}
                    <span className="text-fg-faint">
                        {data.servers.some((server) => server.refreshing) ? (
                            'Asking servers…'
                        ) : (
                            <>
                                Reported <RelativeTime value={lastReport} fallback="never" />
                            </>
                        )}
                    </span>
                </div>
                <div className="flex items-center gap-1.5">
                    <Button size="sm" variant="ghost" icon={<ScrollText />} onClick={() => ctx.open('logs')}>
                        Logs
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        icon={<RefreshCw className={cn(refreshing && 'animate-spin')} />}
                        disabled={refreshing}
                        onClick={() => void refresh()}
                    >
                        Refresh
                    </Button>
                    {data.can.restart && data.services.length > 0 && (
                        <Button size="sm" icon={<RotateCw />} loading={restarting === '*:*'} onClick={() => void restart(null)}>
                            Restart all
                        </Button>
                    )}
                </div>
            </div>

            {data.services.length === 0 ? (
                <EmptyState
                    size="sm"
                    icon={<Boxes />}
                    title="No containers reported yet"
                    description="Each service of the compose file shows up here with its health, image, ports and resource usage once the site is deployed."
                    action={
                        ctx.can('deployments.deploy') && (
                            <Button size="sm" variant="primary" onClick={() => void deployNow(ctx)}>
                                Deploy
                            </Button>
                        )
                    }
                />
            ) : (
                <div className="border-border bg-surface-1 overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-[720px] text-left text-xs" aria-label="Compose services">
                        <thead className="text-fg-faint border-border border-b text-[11px] tracking-wide uppercase">
                            <tr>
                                <th className="px-3 py-2 font-medium">Service</th>
                                <th className="px-3 py-2 font-medium">Image</th>
                                <th className="px-3 py-2 font-medium">Ports</th>
                                <th className="px-3 py-2 text-right font-medium">Restarts</th>
                                <th className="px-3 py-2 text-right font-medium">CPU</th>
                                <th className="px-3 py-2 text-right font-medium">Memory</th>
                                <th className="px-3 py-2">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-border divide-y">
                            {data.services.map((row) => (
                                <ServiceTableRow
                                    key={`${row.service}:${row.server_id}:${row.container}`}
                                    row={row}
                                    multiServer={multiServer}
                                    canRestart={data.can.restart}
                                    restarting={restarting === `${row.service}:${row.server_id}`}
                                    onRestart={() => void restart(row.service, row.server_id)}
                                    onLogs={() => ctx.open('logs', row.service)}
                                />
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

function ServiceTableRow({
    row,
    multiServer,
    canRestart,
    restarting,
    onRestart,
    onLogs,
}: {
    row: ServiceRow;
    multiServer: boolean;
    canRestart: boolean;
    restarting: boolean;
    onRestart: () => void;
    onLogs: () => void;
}) {
    const status = containerStatus(row);
    const published = row.ports.filter((port) => port.host_port);
    const internal = [...new Set(row.ports.filter((port) => !port.host_port).map((port) => port.container_port))];
    const memoryShare = row.memory_bytes !== null && row.memory_limit_bytes ? row.memory_bytes / row.memory_limit_bytes : null;

    return (
        <tr data-service={row.service} className="hover:bg-surface-2/50 transition-colors">
            <td className="px-3 py-2.5">
                <div className="flex items-center gap-2">
                    <div className="grid min-w-0 gap-0.5">
                        <span className="text-fg truncate text-[13px] font-medium">{row.service}</span>
                        <span className="text-fg-faint flex items-center gap-1.5">
                            <StatusBadge status={status.status} label={status.label} />
                            {multiServer && <Tag mono>{row.server_name}</Tag>}
                        </span>
                    </div>
                </div>
            </td>
            <td className="px-3 py-2.5">
                <Tooltip content={<span className="font-mono text-[11px] break-all">{row.image}</span>}>
                    <span className="grid gap-0.5">
                        <span className="text-fg-muted max-w-[14rem] truncate font-mono text-[11px]">{shortImage(row.image)}</span>
                        {row.digest && <span className="text-fg-faint font-mono text-[10px]">{row.digest.replace('sha256:', '').slice(0, 12)}</span>}
                    </span>
                </Tooltip>
            </td>
            <td className="px-3 py-2.5">
                <div className="flex flex-wrap items-center gap-1">
                    {row.public?.url ? (
                        <a
                            href={row.public.url}
                            target="_blank"
                            rel="noreferrer"
                            className="text-primary hover:text-primary-hover inline-flex max-w-[14rem] items-center gap-1 truncate font-mono text-[11px]"
                        >
                            {row.public.url.replace(/^https?:\/\//, '')}
                            <ExternalLink className="size-3 shrink-0" aria-hidden />
                        </a>
                    ) : null}
                    {published
                        .filter((port) => !row.public?.url || port.container_port !== row.public.port)
                        .map((port) => (
                            <Tag key={`${port.host_port}:${port.container_port}`} mono>
                                {port.host_port}→{port.container_port}
                            </Tag>
                        ))}
                    {internal.map((port) => (
                        <Tag key={port} mono>
                            :{port}
                        </Tag>
                    ))}
                    {!row.public?.url && published.length === 0 && internal.length === 0 && <span className="text-fg-faint">—</span>}
                </div>
            </td>
            <td className={cn('px-3 py-2.5 text-right tabular-nums', row.restarts > 0 ? 'text-warning' : 'text-fg-muted')}>{row.restarts}</td>
            <td className="text-fg-muted px-3 py-2.5 text-right tabular-nums">{row.cpu_percent === null ? '—' : `${row.cpu_percent.toFixed(1)}%`}</td>
            <td className="text-fg-muted px-3 py-2.5 text-right tabular-nums">
                <Tooltip content={row.memory_limit_bytes ? `Limit ${formatBytes(row.memory_limit_bytes)}` : 'No reading yet'}>
                    <span>
                        {formatBytes(row.memory_bytes)}
                        {memoryShare !== null && memoryShare > 0.8 && <span className="text-warning"> · {Math.round(memoryShare * 100)}%</span>}
                    </span>
                </Tooltip>
            </td>
            <td className="px-3 py-2.5">
                <div className="flex items-center justify-end gap-0.5">
                    {row.container && <CopyButton value={row.container} label="Copy container name" size="xs" />}
                    <IconButton size="sm" variant="ghost" label={`Logs of ${row.service}`} icon={<ScrollText />} onClick={onLogs} />
                    {canRestart && (
                        <IconButton
                            size="sm"
                            variant="ghost"
                            label={`Restart ${row.service}`}
                            icon={<RotateCw className={cn(restarting && 'animate-spin')} />}
                            onClick={onRestart}
                            disabled={restarting}
                        />
                    )}
                </div>
            </td>
        </tr>
    );
}
