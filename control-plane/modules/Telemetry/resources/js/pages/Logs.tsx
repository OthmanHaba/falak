import { Checkbox } from '@/components/kiln/checkbox';
import { Input } from '@/components/kiln/input';
import { Segmented } from '@/components/kiln/segmented';
import { Select } from '@/components/kiln/select';
import { Tag } from '@/components/kiln/tag';
import ObservabilityLayout from '@/layouts/observability-layout';
import { Search, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { BackendError, NotConfigured } from '../components/backend-state';
import { LogStreamView, useLogStream, type LogFilters } from '../components/log-stream';
import { queryString } from '../lib';

interface Props {
    filters: Omit<LogFilters, 'regex'> & { regex?: boolean | string };
    servers: { id: string; name: string }[];
    sites: { id: string; name: string }[];
    levels: string[];
    configured: boolean;
}

const RANGES = ['15m', '1h', '6h', '24h', '7d'] as const;
const ANY = '__any__';

export default function Logs({ filters: initial, servers, sites, levels, configured }: Props) {
    const [filters, setFilters] = useState<LogFilters>(() => ({
        ...initial,
        range: initial.range ?? (initial.from ? undefined : '1h'),
        regex: initial.regex === true || initial.regex === '1',
    }));
    const [search, setSearch] = useState(filters.search ?? '');
    const [following, setFollowing] = useState(true);
    const stream = useLogStream(filters, { enabled: configured, follow: following });
    const serverNames = useMemo(() => Object.fromEntries(servers.map((server) => [server.id.toLowerCase(), server.name])), [servers]);

    useEffect(() => {
        window.history.replaceState(window.history.state, '', `/observability/logs${queryString({ ...filters })}`);
    }, [filters]);

    const update = (patch: Partial<LogFilters>) => setFilters((current) => ({ ...current, ...patch }));
    const option = (value: string | undefined) => value ?? ANY;
    const fromOption = (value: string) => (value === ANY ? undefined : value);

    return (
        <ObservabilityLayout tab="logs">
            <div className="flex flex-wrap items-center gap-2">
                <form
                    className="min-w-56 flex-1"
                    onSubmit={(event) => {
                        event.preventDefault();
                        update({ search: search.trim() || undefined });
                    }}
                >
                    <Input
                        type="search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        onBlur={() => update({ search: search.trim() || undefined })}
                        prefix={<Search />}
                        placeholder={filters.regex ? 'Regular expression (press Enter)' : 'Filter log lines (press Enter)'}
                        aria-label="Filter log lines"
                        mono
                    />
                </form>
                <label className="text-fg-muted flex h-8 items-center gap-1.5 text-xs">
                    <Checkbox checked={filters.regex === true} onCheckedChange={(checked) => update({ regex: checked === true })} />
                    Regex
                </label>
                <Select
                    size="md"
                    className="w-40"
                    aria-label="Site"
                    value={option(filters.site_id)}
                    onValueChange={(value) => update({ site_id: fromOption(value) })}
                    options={[{ value: ANY, label: 'All sites' }, ...sites.map((site) => ({ value: site.id, label: site.name }))]}
                />
                <Select
                    className="w-36"
                    aria-label="Server"
                    value={option(filters.server_id)}
                    onValueChange={(value) => update({ server_id: fromOption(value) })}
                    options={[{ value: ANY, label: 'All servers' }, ...servers.map((server) => ({ value: server.id, label: server.name }))]}
                />
                <Select
                    className="w-32"
                    aria-label="Level"
                    value={option(filters.level)}
                    onValueChange={(value) => update({ level: fromOption(value) })}
                    options={[{ value: ANY, label: 'Any level' }, ...levels.map((level) => ({ value: level, label: level }))]}
                />
                <Segmented
                    label="Time range"
                    value={(filters.range as (typeof RANGES)[number]) ?? '1h'}
                    onValueChange={(range) => update({ range, from: undefined, to: undefined })}
                    options={RANGES.map((range) => ({ value: range, label: range }))}
                />
            </div>

            {(filters.trace_id || filters.service || filters.from) && (
                <div className="flex flex-wrap items-center gap-1.5 text-xs">
                    <span className="text-fg-faint">Also filtered by</span>
                    {filters.trace_id && (
                        <FilterChip label={`trace ${filters.trace_id.slice(0, 12)}`} onClear={() => update({ trace_id: undefined })} />
                    )}
                    {filters.service && <FilterChip label={`service ${filters.service}`} onClear={() => update({ service: undefined })} />}
                    {filters.from && (
                        <FilterChip label="custom time window" onClear={() => update({ from: undefined, to: undefined, range: '1h' })} />
                    )}
                </div>
            )}

            {!configured ? (
                <NotConfigured backend="Loki" />
            ) : stream.state.status === 'error' ? (
                <BackendError backend="Loki" error={stream.state.error} onRetry={stream.retry} />
            ) : (
                <LogStreamView
                    lines={stream.lines}
                    live={stream.live}
                    following={following}
                    onFollowChange={setFollowing}
                    olderCursor={stream.olderCursor}
                    loadingOlder={stream.loadingOlder}
                    onLoadOlder={() => void stream.loadOlder()}
                    loading={stream.state.status === 'loading'}
                    serverNames={serverNames}
                    height="calc(100vh - 17rem)"
                />
            )}
        </ObservabilityLayout>
    );
}

function FilterChip({ label, onClear }: { label: string; onClear: () => void }) {
    return (
        <Tag className="pr-0.5">
            <span className="flex items-center gap-1">
                {label}
                <button type="button" onClick={onClear} aria-label={`Remove filter ${label}`} className="text-fg-faint hover:text-fg rounded-sm">
                    <X className="size-3" />
                </button>
            </span>
        </Tag>
    );
}
