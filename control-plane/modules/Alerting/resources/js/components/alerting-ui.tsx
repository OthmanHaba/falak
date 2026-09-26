import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { type Severity } from '../types';

const SEVERITY_STYLES: Record<Severity, string> = {
    info: 'bg-blue-500/10 text-blue-700 dark:text-blue-300',
    warning: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
    critical: 'bg-red-500/10 text-red-700 dark:text-red-300',
};

export function SeverityBadge({ severity, className }: { severity: Severity; className?: string }) {
    return (
        <Badge variant="outline" className={cn('border-transparent capitalize', SEVERITY_STYLES[severity], className)}>
            {severity}
        </Badge>
    );
}

export function SeverityDot({ severity }: { severity: Severity }) {
    const color = severity === 'critical' ? 'bg-red-500' : severity === 'warning' ? 'bg-amber-500' : 'bg-blue-500';

    return <span aria-hidden className={cn('mt-1.5 inline-block size-2 shrink-0 rounded-full', color)} />;
}

const TABS = [
    { href: '/alerting/rules', label: 'Rules' },
    { href: '/alerting/channels', label: 'Channels' },
    { href: '/alerting/history', label: 'History' },
];

export function AlertingTabs({ active }: { active: string }) {
    return (
        <nav className="flex gap-1 border-b" aria-label="Alerting sections">
            {TABS.map((tab) => (
                <Link
                    key={tab.href}
                    href={tab.href}
                    className={cn(
                        '-mb-px border-b-2 px-3 py-2 text-sm font-medium',
                        active === tab.href ? 'border-primary text-foreground' : 'text-muted-foreground hover:text-foreground border-transparent',
                    )}
                >
                    {tab.label}
                </Link>
            ))}
        </nav>
    );
}

/** JSON request with session cookie + CSRF token (for endpoints outside Inertia visits). */
export async function jsonRequest<T>(method: 'GET' | 'POST', url: string): Promise<{ ok: boolean; status: number; body: T | null }> {
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf },
    });

    let body: T | null = null;
    try {
        body = (await response.json()) as T;
    } catch {
        body = null;
    }

    return { ok: response.ok, status: response.status, body };
}
