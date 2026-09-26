import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { AlertTriangle, Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { type SiteStatus, type TargetStatus } from '../types';

const SITE_STATUS: Record<SiteStatus, { label: string; className: string }> = {
    ready: { label: 'Ready', className: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' },
    provisioning: { label: 'Preparing', className: 'bg-blue-500/15 text-blue-700 dark:text-blue-300' },
    failed: { label: 'Needs attention', className: 'bg-red-500/15 text-red-700 dark:text-red-300' },
    no_servers: { label: 'No servers', className: 'bg-muted text-muted-foreground' },
};

const TARGET_STATUS: Record<TargetStatus, string> = {
    pending: 'bg-muted text-muted-foreground',
    provisioning: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    ready: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    failed: 'bg-red-500/15 text-red-700 dark:text-red-300',
    removing: 'bg-muted text-muted-foreground',
};

export function SiteStatusBadge({ status }: { status: SiteStatus }) {
    const style = SITE_STATUS[status];

    return (
        <Badge variant="outline" className={cn('border-transparent', style.className)}>
            {style.label}
        </Badge>
    );
}

export function TargetStatusBadge({ status }: { status: TargetStatus }) {
    return (
        <Badge variant="outline" className={cn('border-transparent capitalize', TARGET_STATUS[status])}>
            {status}
        </Badge>
    );
}

export function CopyButton({ value, label = 'Copy' }: { value: string; label?: string }) {
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
        <Button type="button" variant="ghost" size="icon" className="size-6" onClick={copy} aria-label={label} title={label}>
            {copied ? <Check className="size-3.5" /> : <Copy className="size-3.5" />}
        </Button>
    );
}

export function Warnings({ warnings }: { warnings: string[] }) {
    if (warnings.length === 0) {
        return null;
    }

    return (
        <Alert>
            <AlertTriangle className="size-4" />
            <AlertTitle>Source control</AlertTitle>
            <AlertDescription>
                <ul className="list-disc space-y-1 pl-4">
                    {warnings.map((warning) => (
                        <li key={warning}>{warning}</li>
                    ))}
                </ul>
            </AlertDescription>
        </Alert>
    );
}

export function DefinitionRow({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="grid grid-cols-3 gap-2 py-1.5 text-sm">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="col-span-2 min-w-0 break-words">{children}</dd>
        </div>
    );
}

export function formatBytes(bytes: number | null): string {
    if (bytes === null) {
        return 'unknown';
    }

    return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
}

/** JSON request with the Laravel XSRF cookie (for non-Inertia endpoints). */
export async function requestJson<T>(url: string, method: 'GET' | 'POST' = 'GET', body?: unknown): Promise<T> {
    const xsrf = decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    const payload = (await response.json().catch(() => ({}))) as T & { message?: string };

    if (!response.ok) {
        throw new Error(payload.message ?? `HTTP ${response.status}`);
    }

    return payload;
}
