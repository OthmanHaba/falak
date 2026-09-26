import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { CheckCircle2, CircleDashed, CircleSlash, Loader2, XCircle } from 'lucide-react';
import { type DeploymentStatus, type StepStatus } from '../types';

const STATUS_STYLES: Record<DeploymentStatus, string> = {
    queued: 'bg-muted text-muted-foreground',
    building: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
    deploying: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    succeeded: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    failed: 'bg-red-500/15 text-red-700 dark:text-red-300',
    cancelled: 'bg-muted text-muted-foreground',
};

export const TERMINAL: DeploymentStatus[] = ['succeeded', 'failed', 'cancelled'];

export function DeploymentStatusBadge({ status, rolledBack, className }: { status: DeploymentStatus; rolledBack?: boolean; className?: string }) {
    return (
        <Badge variant="outline" className={cn('border-transparent capitalize', STATUS_STYLES[status], className)}>
            {(status === 'building' || status === 'deploying') && <Loader2 className="mr-1 size-3 animate-spin" />}
            {status}
            {rolledBack ? ' · rolled back' : ''}
        </Badge>
    );
}

export function StepIcon({ status, className }: { status: StepStatus | undefined; className?: string }) {
    const base = cn('size-4 shrink-0', className);

    switch (status) {
        case 'running':
            return <Loader2 className={cn(base, 'animate-spin text-blue-500')} aria-label="running" />;
        case 'succeeded':
            return <CheckCircle2 className={cn(base, 'text-emerald-500')} aria-label="succeeded" />;
        case 'failed':
            return <XCircle className={cn(base, 'text-red-500')} aria-label="failed" />;
        case 'skipped':
            return <CircleSlash className={cn(base, 'text-muted-foreground')} aria-label="skipped" />;
        default:
            return <CircleDashed className={cn(base, 'text-muted-foreground/60')} aria-label="pending" />;
    }
}

export function duration(ms: number | null | undefined): string {
    if (ms === null || ms === undefined) {
        return '';
    }

    if (ms < 1000) {
        return `${ms} ms`;
    }

    const s = Math.round(ms / 100) / 10;

    return s < 60 ? `${s}s` : `${Math.floor(s / 60)}m ${Math.round(s % 60)}s`;
}

export function between(from: string | null, to: string | null): string {
    if (!from) {
        return '';
    }

    return duration((to ? new Date(to).getTime() : Date.now()) - new Date(from).getTime());
}

export function when(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '—';
}

export function Commit({ sha, branch }: { sha: string | null; branch?: string | null }) {
    return (
        <span className="font-mono text-xs">
            {branch && <span className="text-muted-foreground">{branch}@</span>}
            {sha ? sha.slice(0, 7) : 'HEAD'}
        </span>
    );
}
