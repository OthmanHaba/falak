import { StatusBadge } from '@/components/falak/status';
import { type SessionStatus } from '../types';

const STATUS: Record<SessionStatus, { status: string; label: string }> = {
    opening: { status: 'running', label: 'Opening' },
    open: { status: 'active', label: 'Open' },
    closed: { status: 'inactive', label: 'Closed' },
    failed: { status: 'failed', label: 'Failed' },
};

export function SessionStatusBadge({ status, className }: { status: SessionStatus; className?: string }) {
    return <StatusBadge status={STATUS[status].status} label={STATUS[status].label} className={className} />;
}
