import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { type BackupStatus, type ResourceStatus, type RestoreStatus } from '../types';

type AnyStatus = ResourceStatus | BackupStatus | RestoreStatus;

const STATUS_STYLES: Record<AnyStatus, string> = {
    pending: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
    running: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    active: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    succeeded: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    failed: 'bg-red-500/15 text-red-700 dark:text-red-300',
    deleting: 'bg-muted text-muted-foreground',
    pruned: 'bg-muted text-muted-foreground',
};

export function StatusBadge({ status, title, className }: { status: AnyStatus; title?: string | null; className?: string }) {
    return (
        <Badge variant="outline" title={title ?? undefined} className={cn('border-transparent capitalize', STATUS_STYLES[status], className)}>
            {status}
        </Badge>
    );
}

export function CopyButton({ value, label = 'Copy', className }: { value: string; label?: string; className?: string }) {
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

export function formatBytes(bytes: number | null): string {
    if (bytes === null) {
        return '—';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
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

/** Request helper for JSON endpoints (session auth + CSRF). */
export async function postJson<T>(url: string): Promise<T> {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token },
    });

    if (!response.ok) {
        throw new Error(response.status === 403 ? 'You are not allowed to do this.' : `HTTP ${response.status}`);
    }

    return (await response.json()) as T;
}
