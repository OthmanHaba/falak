import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { type ApplyStatus, type InstallStatus, type TlsMode } from '../types';

const APPLY_STYLES: Record<ApplyStatus | InstallStatus, string> = {
    pending: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
    applied: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    installed: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    failed: 'bg-red-500/15 text-red-700 dark:text-red-300',
    error: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    removing: 'bg-muted text-muted-foreground',
};

export function StatusBadge({ status, className }: { status: ApplyStatus | InstallStatus; className?: string }) {
    return (
        <Badge variant="outline" className={cn('border-transparent capitalize', APPLY_STYLES[status], className)}>
            {status}
        </Badge>
    );
}

const TLS_LABELS: Record<TlsMode, string> = {
    auto: 'Auto TLS',
    dns: 'DNS-01',
    custom: 'Custom cert',
    internal: 'Internal CA',
    off: 'HTTP only',
};

export function TlsBadge({ mode }: { mode: TlsMode }) {
    return (
        <Badge variant={mode === 'off' ? 'destructive' : 'secondary'} className="font-normal">
            {TLS_LABELS[mode]}
        </Badge>
    );
}

export function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}
