import { Button } from '@/components/kiln/button';
import { EmptyState } from '@/components/kiln/empty-state';
import { Link } from '@inertiajs/react';
import { CloudOff, PlugZap, RotateCw, TriangleAlert } from 'lucide-react';
import { HttpError } from '../lib';

export type Backend = 'Loki' | 'Tempo' | 'metrics backend';

const ENV: Record<Backend, string> = { Loki: 'KILN_LOKI_URL', Tempo: 'KILN_TEMPO_URL', 'metrics backend': 'KILN_METRICS_QUERY_URL' };

const WHAT: Record<Backend, string> = {
    Loki: 'Logs from your sites, servers and the Kiln agent',
    Tempo: 'Distributed traces of requests, jobs and commands',
    'metrics backend': 'CPU, memory, request rate and latency charts',
};

/** "Not configured" state: explains what the tab shows and how to turn it on (§1.8 empty states teach). */
export function NotConfigured({ backend, size = 'md' }: { backend: Backend; size?: 'sm' | 'md' }) {
    return (
        <EmptyState
            size={size}
            icon={<PlugZap />}
            title={`Connect ${backend === 'metrics backend' ? 'a metrics backend' : backend} to see this`}
            description={
                <>
                    {WHAT[backend]} appear here once the control plane can query {backend}. Set{' '}
                    <code className="font-mono text-xs">{ENV[backend]}</code> or configure it in observability settings.
                </>
            }
            action={
                <Button asChild variant="secondary" size="sm">
                    <Link href="/telemetry/settings">Observability settings</Link>
                </Button>
            }
        />
    );
}

/**
 * Error state for a failed backend query: 503 → the backend is unreachable/unconfigured; other codes → the query
 * itself failed. Always offers a retry.
 */
export function BackendError({
    backend,
    error,
    onRetry,
    size = 'md',
}: {
    backend: Backend;
    error: unknown;
    onRetry?: () => void;
    size?: 'sm' | 'md';
}) {
    const unreachable = error instanceof HttpError && error.status === 503;
    const message = error instanceof Error ? error.message : String(error);

    return (
        <EmptyState
            size={size}
            icon={unreachable ? <CloudOff /> : <TriangleAlert />}
            title={unreachable ? `${backend.charAt(0).toUpperCase()}${backend.slice(1)} is unreachable` : 'The query failed'}
            description={
                unreachable ? (
                    <>
                        Kiln couldn't reach {backend}. Check that it is running and reachable from the control plane.
                        <span className="text-fg-faint mt-1 block font-mono text-xs">{message}</span>
                    </>
                ) : (
                    <span className="font-mono text-xs">{message}</span>
                )
            }
            action={
                onRetry && (
                    <Button variant="secondary" size="sm" icon={<RotateCw />} onClick={onRetry}>
                        Retry
                    </Button>
                )
            }
        />
    );
}
