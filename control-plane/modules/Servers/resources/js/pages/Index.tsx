import { Button } from '@/components/kiln/button';
import { CopyButton, copyText } from '@/components/kiln/copy-button';
import { DataTable, type DataTableColumn } from '@/components/kiln/data-table';
import { EmptyState } from '@/components/kiln/empty-state';
import { Input } from '@/components/kiln/input';
import { Kbd } from '@/components/kiln/kbd';
import { RelativeTime } from '@/components/kiln/relative-time';
import { Select } from '@/components/kiln/select';
import { ServiceIcon } from '@/components/kiln/service-icon';
import { Skeleton } from '@/components/kiln/skeleton';
import { StatusDot } from '@/components/kiln/status';
import { toast } from '@/components/kiln/toast';
import { Tooltip } from '@/components/kiln/tooltip';
import InfrastructureLayout from '@/layouts/infrastructure-layout';
import { serverState, ServerStatusBadge } from '@/layouts/server-layout';
import { echo } from '@/lib/echo';
import { Link, router, usePoll } from '@inertiajs/react';
import { Activity, Copy, Plus, Search, Server as ServerIcon, Settings, SquareTerminal, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { ProviderIcon, Sparkline, UsageMeter } from '../components/server-ui';
import { type FleetService, type ServerSummary, type SparklinePoint } from '../types';

type FleetServer = ServerSummary & { services: FleetService[] };

interface Props {
    servers: FleetServer[];
    /** Deferred: loads after the first paint. */
    sparklines?: Record<string, SparklinePoint[]>;
    filters: { search?: string; type?: string; status?: string };
    types: { value: string; label: string }[];
    can: { create: boolean };
}

const ALL = 'all';

const STATE_FILTERS = [
    { value: ALL, label: 'All statuses' },
    { value: 'online', label: 'Online' },
    { value: 'offline', label: 'Offline' },
    { value: 'provisioning', label: 'Provisioning' },
    { value: 'waiting', label: 'Waiting for agent' },
    { value: 'failed', label: 'Failed' },
];

const TRANSITIONAL = new Set(['creating', 'provisioning', 'deleting']);

/** Subscribe to every visible server's channel; any update refreshes the fleet in place. */
function useFleetChannels(ids: string[], onUpdate: () => void) {
    const handler = useRef(onUpdate);
    handler.current = onUpdate;
    const key = ids.join(',');

    useEffect(() => {
        const client = echo();
        if (!client || !key) return;
        const names = key.split(',').map((id) => `servers.${id}`);
        names.forEach((name) => client.private(name).listen('.server.updated', () => handler.current()));

        return () => names.forEach((name) => client.leave(name));
    }, [key]);
}

function ServiceStack({ services }: { services: FleetService[] }) {
    if (services.length === 0) {
        return <span className="text-fg-faint text-xs">—</span>;
    }

    const shown = services.slice(0, 4);
    const names = services.map((service) => service.name).join(', ');

    return (
        <Tooltip content={names}>
            <span className="flex items-center gap-1.5" aria-label={`${services.length} service${services.length === 1 ? '' : 's'}: ${names}`}>
                <span className="flex -space-x-1">
                    {shown.map((service) => (
                        <span
                            key={`${service.kind}-${service.id}`}
                            className="border-surface-1 bg-surface-2 flex size-5 items-center justify-center rounded-full border"
                        >
                            <ServiceIcon name={service.icon} size={11} />
                        </span>
                    ))}
                </span>
                <span className="text-fg-muted tabular text-xs">{services.length}</span>
            </span>
        </Tooltip>
    );
}

export default function Index({ servers, sparklines, filters, types, can }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [type, setType] = useState(filters.type ?? ALL);
    const [state, setState] = useState(ALL);
    const searchRef = useRef<HTMLInputElement>(null);

    const transitional = servers.some((server) => TRANSITIONAL.has(server.status));
    usePoll(transitional ? 5_000 : 30_000, { only: ['servers', 'sparklines'] });
    useFleetChannels(
        servers.map((server) => server.id),
        () => router.reload({ only: ['servers'] }),
    );

    // "/" focuses the search.
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;
            if (event.key !== '/' || target?.closest('input, textarea, [contenteditable="true"]')) return;
            event.preventDefault();
            searchRef.current?.focus();
        };
        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    const visible = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return servers.filter((server) => {
            if (type !== ALL && server.type !== type) return false;
            if (state !== ALL && serverState(server).status !== state) return false;
            if (!needle) return true;

            return [server.name, server.ipv4 ?? '', server.region ?? '', server.provider_label, ...server.services.map((s) => s.name)]
                .join(' ')
                .toLowerCase()
                .includes(needle);
        });
    }, [servers, search, type, state]);

    const counts = useMemo(() => {
        const tally: Record<string, number> = {};
        servers.forEach((server) => {
            const key = serverState(server).status;
            tally[key] = (tally[key] ?? 0) + 1;
        });

        return tally;
    }, [servers]);

    const filtered = search.trim() !== '' || type !== ALL || state !== ALL;
    const clearFilters = () => {
        setSearch('');
        setType(ALL);
        setState(ALL);
    };

    const columns: DataTableColumn<FleetServer>[] = [
        {
            id: 'name',
            header: 'Name',
            sortValue: (server) => server.name,
            cell: (server) => {
                const spec = serverState(server);

                return (
                    <div className="flex min-w-0 items-center gap-2.5 py-1.5">
                        <StatusDot status={spec.status} tone={spec.tone} pulse={spec.pulse} label={spec.label} />
                        <div className="grid min-w-0">
                            <Link
                                href={`/servers/${server.id}`}
                                className="text-fg truncate font-medium hover:underline"
                                onClick={(event) => event.stopPropagation()}
                            >
                                {server.name}
                            </Link>
                            <span className="text-fg-faint truncate text-xs">{server.type_label}</span>
                        </div>
                    </div>
                );
            },
        },
        {
            id: 'status',
            header: 'Status',
            sortValue: (server) => serverState(server).label,
            cell: (server) => <ServerStatusBadge server={server} />,
        },
        {
            id: 'ip',
            header: 'IP address',
            hideOnMobile: true,
            sortValue: (server) => server.ipv4,
            cell: (server) =>
                server.ipv4 ? (
                    <span className="flex items-center gap-1" onClick={(event) => event.stopPropagation()}>
                        <span className="font-mono text-xs">{server.ipv4}</span>
                        <CopyButton
                            value={server.ipv4}
                            label={`Copy IP of ${server.name}`}
                            size="xs"
                            className="opacity-0 group-hover:opacity-100 focus-visible:opacity-100"
                        />
                    </span>
                ) : (
                    <span className="text-fg-faint text-xs">—</span>
                ),
        },
        {
            id: 'provider',
            header: 'Provider',
            hideOnMobile: true,
            sortValue: (server) => `${server.provider_label} ${server.region ?? ''}`,
            cell: (server) => (
                <span className="text-fg-muted flex min-w-0 items-center gap-2 text-xs">
                    <ProviderIcon provider={server.provider} size={14} className="text-fg-faint" />
                    <span className="truncate">
                        {server.provider === 'custom' ? 'Custom' : server.provider_label}
                        {server.region && <span className="text-fg-faint"> · {server.region}</span>}
                    </span>
                </span>
            ),
        },
        {
            id: 'cpu',
            header: 'CPU',
            hideOnMobile: true,
            sortValue: (server) => server.cpu_percent,
            cell: (server) => {
                if (!server.agent) return <span className="text-fg-faint text-xs">—</span>;
                const points = sparklines?.[server.id];

                return (
                    <span className="flex items-center gap-2">
                        {sparklines === undefined ? (
                            <Skeleton className="h-5 w-[72px]" />
                        ) : (
                            <Sparkline values={(points ?? []).map((point) => point.cpu)} label={`CPU of ${server.name}, last hour`} />
                        )}
                        <span className="text-fg-muted tabular w-9 text-right text-xs">
                            {server.cpu_percent !== null ? `${server.cpu_percent.toFixed(0)}%` : '—'}
                        </span>
                    </span>
                );
            },
        },
        {
            id: 'memory',
            header: 'Memory',
            hideOnMobile: true,
            sortValue: (server) => server.memory_percent,
            cell: (server) => <UsageMeter value={server.memory_percent} label={`Memory of ${server.name}`} />,
        },
        {
            id: 'services',
            header: 'Services',
            sortValue: (server) => server.services.length,
            cell: (server) => <ServiceStack services={server.services} />,
        },
        {
            id: 'seen',
            header: 'Last seen',
            hideOnMobile: true,
            align: 'right',
            sortValue: (server) => server.agent?.last_heartbeat_at ?? '',
            cell: (server) => <RelativeTime value={server.agent?.last_heartbeat_at} className="text-fg-muted text-xs" fallback="never" />,
        },
    ];

    const summary = [
        { key: 'online', label: 'online' },
        { key: 'provisioning', label: 'provisioning' },
        { key: 'waiting', label: 'waiting for agent' },
        { key: 'offline', label: 'offline' },
        { key: 'failed', label: 'failed' },
    ].filter((item) => counts[item.key]);

    return (
        <InfrastructureLayout
            section="servers"
            title="Servers"
            description="The machines your services run on, managed by the Kiln agent."
            actions={
                can.create && (
                    <Button variant="primary" asChild>
                        <Link href="/servers/create">
                            <Plus aria-hidden /> Add server
                        </Link>
                    </Button>
                )
            }
        >
            {servers.length === 0 ? (
                <EmptyState
                    icon={<ServerIcon />}
                    title="No servers yet"
                    description="Servers run your sites and databases. Create one at a cloud provider, or bring any Ubuntu machine and install the agent with one command."
                    action={
                        can.create && (
                            <Button variant="primary" asChild>
                                <Link href="/servers/create">
                                    <Plus aria-hidden /> Add server
                                </Link>
                            </Button>
                        )
                    }
                    secondary={
                        can.create && (
                            <Button variant="ghost" asChild>
                                <Link href="/servers/create?provider=custom">Bring your own server</Link>
                            </Button>
                        )
                    }
                />
            ) : (
                <div className="grid gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="w-full sm:w-72">
                            <Input
                                ref={searchRef}
                                type="search"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Search name, IP, region, service"
                                aria-label="Search servers"
                                prefix={<Search />}
                                suffix={search ? undefined : <Kbd>/</Kbd>}
                            />
                        </div>
                        <Select
                            value={type}
                            onValueChange={setType}
                            options={[{ value: ALL, label: 'All types' }, ...types]}
                            aria-label="Filter by type"
                            className="w-40"
                        />
                        <Select value={state} onValueChange={setState} options={STATE_FILTERS} aria-label="Filter by status" className="w-44" />
                        {filtered && (
                            <Button variant="ghost" size="sm" icon={<X />} onClick={clearFilters}>
                                Clear
                            </Button>
                        )}
                        <p className="text-fg-faint ml-auto text-xs" aria-live="polite">
                            <span className="tabular">{servers.length}</span> server{servers.length === 1 ? '' : 's'}
                            {summary.map((item) => (
                                <span key={item.key}>
                                    {' · '}
                                    <span className="tabular">{counts[item.key]}</span> {item.label}
                                </span>
                            ))}
                        </p>
                    </div>

                    <DataTable
                        label="Servers"
                        rows={visible}
                        rowKey={(server) => server.id}
                        columns={columns}
                        defaultSort={{ column: 'name', direction: 'asc' }}
                        onRowClick={(server) => router.visit(`/servers/${server.id}`)}
                        empty={{
                            icon: <Search />,
                            title: 'No servers match these filters',
                            description: 'Try another name, IP or status.',
                            action: (
                                <Button variant="secondary" size="sm" onClick={clearFilters}>
                                    Clear filters
                                </Button>
                            ),
                            size: 'sm',
                        }}
                        rowActions={(server) => [
                            { label: 'Open', icon: <ServerIcon />, href: `/servers/${server.id}` },
                            { label: 'Metrics', icon: <Activity />, href: `/servers/${server.id}/metrics` },
                            server.status === 'active'
                                ? { label: 'Terminal', icon: <SquareTerminal />, href: `/servers/${server.id}/terminal` }
                                : { label: 'Terminal', icon: <SquareTerminal />, disabled: true },
                            {
                                label: 'Copy IP address',
                                icon: <Copy />,
                                disabled: !server.ipv4,
                                onSelect: () =>
                                    void copyText(server.ipv4 ?? '').then((ok) =>
                                        ok ? toast.success('IP address copied') : toast.error('Could not copy'),
                                    ),
                            },
                            { type: 'separator' },
                            { label: 'Settings', icon: <Settings />, href: `/servers/${server.id}/settings` },
                        ]}
                    />
                </div>
            )}
        </InfrastructureLayout>
    );
}
