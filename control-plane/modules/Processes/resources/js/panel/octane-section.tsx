import { KeyValue, RelativeTime, Section, SkeletonRows, StatusBadge } from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { type ServiceTabProps } from '@/lib/registry';

type OctaneRouteStatus = 'listening' | 'starting' | 'failed' | 'draining' | 'pending';

interface OctaneData {
    enabled: boolean;
    server: string | null;
    server_label: string | null;
    port: number | null;
    aux_port: number | null;
    servers: {
        server_id: string;
        server_name: string;
        status: OctaneRouteStatus;
        port: number | null;
        error: string | null;
        checked_at: string | null;
        listening_at: string | null;
    }[];
}

/** Status language (UI_DESIGN §2) for Octane routing per server. */
const STATUS: Record<OctaneRouteStatus, { status: string; label: string; hint: string }> = {
    listening: { status: 'active', label: 'Listening', hint: 'Caddy proxies to Octane' },
    starting: { status: 'provisioning', label: 'Starting', hint: 'Served directly until Octane answers' },
    failed: { status: 'failed', label: 'Not listening', hint: 'Served directly; re-checked after restarts' },
    draining: { status: 'provisioning', label: 'Stopping', hint: 'Caddy is switching back first' },
    pending: { status: 'pending', label: 'Waiting for server', hint: 'The server is still being prepared' },
};

/**
 * Settings → Laravel: where Octane runs and whether the edge proxies to it on each server (polled while open).
 */
export function OctaneSection({ ctx }: ServiceTabProps) {
    const { data } = useJson<OctaneData>(`/sites/${ctx.service.ref_id}/processes/octane`, { interval: 5000 });

    if (!data) return <SkeletonRows rows={2} />;
    if (!data.enabled && data.servers.length === 0) return null;

    return (
        <Section
            title="Octane"
            description="Caddy serves files in public/ itself and proxies every other request to Octane once it answers on its port. Deploys restart Octane while Caddy holds requests."
        >
            <KeyValue
                columns={3}
                items={[
                    { label: 'Server', value: data.server_label ?? '—' },
                    { label: 'Port', value: data.port ? `127.0.0.1:${data.port}` : '—', mono: true },
                    {
                        label: data.server === 'roadrunner' ? 'RPC port' : 'Admin port',
                        value: data.server === 'swoole' || !data.aux_port ? '—' : `127.0.0.1:${data.aux_port}`,
                        mono: true,
                    },
                ]}
            />
            <ul className="border-border divide-border divide-y rounded-md border" aria-label="Octane per server">
                {data.servers.map((row) => {
                    const spec = STATUS[row.status];

                    return (
                        <li key={row.server_id} className="grid gap-1 px-3 py-2">
                            <div className="flex items-center justify-between gap-3">
                                <span className="text-fg text-sm font-medium">{row.server_name}</span>
                                <StatusBadge status={spec.status} label={spec.label} />
                            </div>
                            <p className="text-fg-muted text-xs">
                                {spec.hint}
                                {row.listening_at && row.status === 'listening' && (
                                    <>
                                        {' · since '}
                                        <RelativeTime value={row.listening_at} />
                                    </>
                                )}
                                {row.checked_at && row.status !== 'listening' && (
                                    <>
                                        {' · checked '}
                                        <RelativeTime value={row.checked_at} />
                                    </>
                                )}
                            </p>
                            {row.error && row.status === 'failed' && <p className="text-danger font-mono text-[11px]">{row.error}</p>}
                        </li>
                    );
                })}
            </ul>
        </Section>
    );
}
