import { Button, EmptyState, LogViewer, Select, type LogLine } from '@/components/kiln';
import { useJson } from '@/hooks/use-json';
import { type ServicePanelContext } from '@/lib/registry';
import { Globe, RefreshCw, ScrollText } from 'lucide-react';
import { useMemo, useState } from 'react';
import { type Deployment } from '../types';

/** One edge request (Telemetry AccessLogEntry, GET /telemetry/sites/{site}/access-logs/data). */
interface AccessEntry {
    ts: string;
    at: string;
    method: string;
    path: string;
    query: string | null;
    status: number;
    duration_ms: number | null;
    bytes: number | null;
    client_ip: string | null;
    user_agent: string | null;
    host: string | null;
    server: string | null;
}

interface AccessResponse {
    configured: boolean;
    entries: AccessEntry[];
    cursor: string | null;
}

type StatusFilter = 'all' | '2xx' | '3xx' | '4xx' | '5xx';

const STATUS_OPTIONS: { value: StatusFilter; label: string }[] = [
    { value: 'all', label: 'All statuses' },
    { value: '2xx', label: '2xx' },
    { value: '3xx', label: '3xx' },
    { value: '4xx', label: '4xx' },
    { value: '5xx', label: '5xx' },
];

function formatBytes(bytes: number | null): string {
    if (bytes === null) return '—';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

function toLine(entry: AccessEntry): LogLine {
    const target = entry.query ? `${entry.path}?${entry.query}` : entry.path;
    const duration = entry.duration_ms !== null ? `${entry.duration_ms < 10 ? entry.duration_ms.toFixed(1) : Math.round(entry.duration_ms)}ms` : '—';
    const parts = [entry.method.padEnd(6), String(entry.status), duration.padStart(7), formatBytes(entry.bytes).padStart(8), target];
    const origin = [entry.client_ip, entry.server].filter(Boolean).join(' → ');

    return {
        time: entry.at,
        text: `${parts.join('  ')}${origin ? `   ${origin}` : ''}${entry.user_agent ? `   "${entry.user_agent}"` : ''}`,
        level: entry.status >= 500 ? 'error' : entry.status >= 400 ? 'warning' : 'info',
    };
}

/**
 * Network Logs of a deployment: the HTTP requests the edge served from this deployment's release (per-site access logs
 * shipped by the agents to Loki), newest last, refreshed every 10 s while the release is live.
 */
export function NetworkLogs({ ctx, deployment, live }: { ctx: ServicePanelContext; deployment: Deployment; live: boolean }) {
    const [status, setStatus] = useState<StatusFilter>('all');
    const since = deployment.started_at ?? deployment.created_at;
    const url = deployment.release_id
        ? `/telemetry/sites/${ctx.service.ref_id}/access-logs/data?${new URLSearchParams({
              release: deployment.release_id,
              since,
              limit: '500',
              ...(status !== 'all' ? { status } : {}),
          }).toString()}`
        : null;
    const { data, error, loading, reload } = useJson<AccessResponse>(url, { unwrap: false, interval: live ? 10_000 : false });
    const lines = useMemo(() => (data?.entries ?? []).slice().reverse().map(toLine), [data]);

    if (!deployment.release_id) {
        return (
            <div className="px-5 pt-5 pb-8 sm:px-7">
                <EmptyState
                    icon={<Globe />}
                    title="No release yet"
                    description="Requests are listed once this deployment's release serves traffic."
                />
            </div>
        );
    }

    if (data && !data.configured) {
        return (
            <div className="px-5 pt-5 pb-8 sm:px-7">
                <EmptyState
                    icon={<Globe />}
                    title="Logs backend not configured"
                    description="Network logs are read from Loki. Configure it under Settings → Observability."
                    action={
                        ctx.can('telemetry.view') && (
                            <Button icon={<ScrollText />} onClick={() => ctx.open('logs')}>
                                Open service logs
                            </Button>
                        )
                    }
                />
            </div>
        );
    }

    return (
        <LogViewer
            variant="flush"
            lines={lines}
            label={`HTTP requests served by deployment #${deployment.number}`}
            filename={`deployment-${deployment.number}-requests.log`}
            follow={live}
            emptyText={
                error
                    ? `Could not load the access log: ${error}`
                    : loading && !data
                      ? 'Loading requests…'
                      : 'No requests reached this release yet (or its servers run an agent without access logs — upgrade the agent under Servers).'
            }
            toolbar={
                <>
                    <Select<StatusFilter> size="sm" value={status} onValueChange={setStatus} options={STATUS_OPTIONS} aria-label="Filter by status" />
                    <Button variant="ghost" size="sm" icon={<RefreshCw />} onClick={() => void reload()} disabled={loading}>
                        Refresh
                    </Button>
                </>
            }
        />
    );
}
