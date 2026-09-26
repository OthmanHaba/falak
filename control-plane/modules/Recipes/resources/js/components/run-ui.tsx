import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { type RunStatus, type TargetStatus } from '../types';

const STYLES: Record<RunStatus | TargetStatus, string> = {
    pending: 'bg-muted text-muted-foreground',
    queued: 'bg-muted text-muted-foreground',
    running: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    succeeded: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    failed: 'bg-red-500/15 text-red-700 dark:text-red-300',
    partial: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    unavailable: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
};

export function RunStatusBadge({ status, className }: { status: RunStatus | TargetStatus; className?: string }) {
    return (
        <Badge variant="outline" className={cn('border-transparent capitalize', STYLES[status], className)}>
            {status}
        </Badge>
    );
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

export function ScriptBlock({ script, className }: { script: string; className?: string }) {
    return (
        <pre className={cn('max-h-80 overflow-auto rounded-md bg-neutral-950 p-3 font-mono text-xs leading-relaxed text-neutral-100', className)}>
            {script}
        </pre>
    );
}
