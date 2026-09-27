import { StatusBadge } from '@/components/kiln/status';
import { type ApplyStatus } from '../types';

const STATUS: Record<ApplyStatus, { status: string; label: string }> = {
    pending: { status: 'queued', label: 'Pending' },
    applying: { status: 'running', label: 'Applying' },
    applied: { status: 'active', label: 'Applied' },
    failed: { status: 'failed', label: 'Failed' },
};

/** Convergence state of a firewall / WireGuard config on a server. */
export function ApplyStatusBadge({ status, className }: { status: ApplyStatus | null | undefined; className?: string }) {
    if (!status) {
        return <StatusBadge status="inactive" label="Not configured" className={className} />;
    }

    return <StatusBadge status={STATUS[status].status} label={STATUS[status].label} className={className} />;
}
