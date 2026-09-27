import { Select } from '@/components/kiln/select';
import { SkeletonRows } from '@/components/kiln/skeleton';
import { useMemo, useState } from 'react';
import { BackendError, NotConfigured } from '../components/backend-state';
import { LogStreamView, useLogStream, type LogFilters } from '../components/log-stream';
import { useSiteTelemetryContext } from '../components/site-context';

const ANY = '__any__';

export interface SiteLogsProps {
    siteId: string;
    /** Compose sites: show one compose service (Services tab → logs). */
    composeService?: string;
    onClearComposeService?: () => void;
}

/**
 * Service panel → Logs tab (docs/UI_DESIGN.md §5.1): live Loki stream for one site with search, level and server
 * filters, follow/pause, and click a line → its trace.
 */
export default function SiteLogs({ siteId, composeService, onClearComposeService }: SiteLogsProps) {
    const [context, retryContext] = useSiteTelemetryContext(siteId);
    const [level, setLevel] = useState<string | undefined>();
    const [server, setServer] = useState<string | undefined>();
    const [range, setRange] = useState('1h');
    const [following, setFollowing] = useState(true);
    const configured = context.status === 'ready' && context.data.configured.logs;
    const filters = useMemo<LogFilters>(() => ({ site_id: siteId, level, server_id: server, range, compose_service: composeService }),
        [siteId, level, server, range, composeService],
    );
    const stream = useLogStream(filters, { enabled: configured, follow: following });
    const servers = useMemo(() => (context.status === 'ready' ? context.data.servers : []), [context]);
    const serverNames = useMemo(() => Object.fromEntries(servers.map((item) => [item.id.toLowerCase(), item.name])), [servers]);

    if (context.status === 'loading') return <SkeletonRows rows={8} />;
    if (context.status === 'error') return <BackendError size="sm" backend="Loki" error={context.error} onRetry={retryContext} />;
    if (!configured) return <NotConfigured size="sm" backend="Loki" />;
    if (stream.state.status === 'error') return <BackendError size="sm" backend="Loki" error={stream.state.error} onRetry={stream.retry} />;

    return (
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
            height="min(640px, calc(100vh - 16rem))"
            emptyText="No log lines from this site in this time range."
            toolbar={
                <>
                    {composeService && (
                        <button
                            type="button"
                            onClick={onClearComposeService}
                            className="border-border bg-surface-2 text-fg-muted hover:text-fg inline-flex h-7 items-center gap-1.5 rounded-md border px-2 font-mono text-xs"
                            title="Show every service"
                            data-testid="compose-service-filter"
                        >
                            service: {composeService} <span aria-hidden>×</span>
                        </button>
                    )}
                    <Select
                        size="sm"
                        className="w-28"
                        aria-label="Level"
                        value={level ?? ANY}
                        onValueChange={(value) => setLevel(value === ANY ? undefined : value)}
                        options={[{ value: ANY, label: 'Any level' }, ...context.data.levels.map((item) => ({ value: item, label: item }))]}
                    />
                    {servers.length > 1 && (
                        <Select
                            size="sm"
                            className="w-28"
                            aria-label="Server"
                            value={server ?? ANY}
                            onValueChange={(value) => setServer(value === ANY ? undefined : value)}
                            options={[{ value: ANY, label: 'All servers' }, ...servers.map((item) => ({ value: item.id, label: item.name }))]}
                        />
                    )}
                    <Select
                        size="sm"
                        className="w-20"
                        aria-label="Time range"
                        value={range}
                        onValueChange={setRange}
                        options={['15m', '1h', '6h', '24h', '7d'].map((value) => ({ value, label: value }))}
                    />
                </>
            }
        />
    );
}
