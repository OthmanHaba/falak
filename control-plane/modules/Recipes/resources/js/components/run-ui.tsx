import { CodeBlock } from '@/components/falak/code-block';
import { StatusBadge } from '@/components/falak/status';
import { type RunStatus, type TargetStatus } from '../types';

const STATUS: Record<RunStatus | TargetStatus, { status: string; label: string; tone?: 'warning' }> = {
    pending: { status: 'queued', label: 'Pending' },
    queued: { status: 'queued', label: 'Queued' },
    running: { status: 'running', label: 'Running' },
    succeeded: { status: 'succeeded', label: 'Succeeded' },
    failed: { status: 'failed', label: 'Failed' },
    partial: { status: 'degraded', label: 'Partial', tone: 'warning' },
    unavailable: { status: 'degraded', label: 'Unavailable', tone: 'warning' },
};

export function RunStatusBadge({ status, className }: { status: RunStatus | TargetStatus; className?: string }) {
    const spec = STATUS[status];

    return <StatusBadge status={spec.status} label={spec.label} tone={spec.tone} className={className} />;
}

export function formatDuration(ms: number | null): string {
    if (ms === null) {
        return '—';
    }

    if (ms < 1000) {
        return `${ms} ms`;
    }

    const seconds = ms / 1000;

    if (seconds < 60) {
        return `${seconds.toFixed(1)} s`;
    }

    return `${Math.floor(seconds / 60)}m ${Math.round(seconds % 60)}s`;
}

export function ScriptBlock({ script, title, className }: { script: string; title?: string; className?: string }) {
    return <CodeBlock code={script} title={title} maxHeight={320} className={className} />;
}
