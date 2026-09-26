import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { type ServerStatus } from '../types';

const STATUS_STYLES: Record<ServerStatus, string> = {
    creating: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
    provisioning: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    active: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    error: 'bg-red-500/15 text-red-700 dark:text-red-300',
    deleting: 'bg-muted text-muted-foreground',
};

export function ServerStatusBadge({ status, className }: { status: ServerStatus; className?: string }) {
    return (
        <Badge variant="outline" className={cn('border-transparent capitalize', STATUS_STYLES[status], className)}>
            {status}
        </Badge>
    );
}

export function AgentDot({ status }: { status: 'online' | 'offline' | 'revoked' | null | undefined }) {
    const color = status === 'online' ? 'bg-emerald-500' : status === 'offline' ? 'bg-red-500' : 'bg-neutral-400';
    const label = status ?? 'no agent';

    return (
        <span className="inline-flex items-center gap-1.5 text-xs" title={`Agent ${label}`}>
            <span className={cn('size-2 rounded-full', color)} />
            <span className="text-muted-foreground capitalize">{label}</span>
        </span>
    );
}

export function CopyButton({ value, className, label = 'Copy' }: { value: string; className?: string; label?: string }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1500);
        } catch {
            setCopied(false);
        }
    };

    return (
        <Button type="button" variant="ghost" size="icon" className={cn('size-6', className)} onClick={copy} aria-label={label} title={label}>
            {copied ? <Check className="size-3.5" /> : <Copy className="size-3.5" />}
        </Button>
    );
}

export function UsageBar({ value, label }: { value: number | null; label: string }) {
    if (value === null) {
        return <span className="text-muted-foreground text-xs">—</span>;
    }

    const clamped = Math.max(0, Math.min(100, value));
    const color = clamped > 90 ? 'bg-red-500' : clamped > 75 ? 'bg-amber-500' : 'bg-emerald-500';

    return (
        <div className="flex items-center gap-2" title={`${label} ${clamped.toFixed(1)}%`}>
            <div className="bg-muted h-1.5 w-16 overflow-hidden rounded-full">
                <div className={cn('h-full rounded-full', color)} style={{ width: `${clamped}%` }} />
            </div>
            <span className="text-muted-foreground w-10 text-right text-xs tabular-nums">{clamped.toFixed(0)}%</span>
        </div>
    );
}

export function formatBytes(bytes: number | null | undefined): string {
    if (bytes === null || bytes === undefined) {
        return '—';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(value >= 10 || unit === 0 ? 0 : 1)} ${units[unit]}`;
}
