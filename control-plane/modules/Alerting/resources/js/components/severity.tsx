import { StatusBadge, StatusDot, type StatusTone } from '@/components/kiln/status';
import { type Severity } from '../types';

export const SEVERITY_TONE: Record<Severity, StatusTone> = { info: 'info', warning: 'warning', critical: 'danger' };
const LABEL: Record<Severity, string> = { info: 'Info', warning: 'Warning', critical: 'Critical' };

export function SeverityBadge({ severity }: { severity: Severity }) {
    return <StatusBadge status={severity} tone={SEVERITY_TONE[severity]} label={LABEL[severity]} />;
}

export function SeverityIndicator({ severity, className }: { severity: Severity; className?: string }) {
    return <StatusDot status={severity} tone={SEVERITY_TONE[severity]} label={`${LABEL[severity]} severity`} className={className} />;
}

/** Navigate to a notification/alert URL: in-app URLs via Inertia, others in the same tab. */
export function openUrl(url: string, visit: (path: string) => void): void {
    const target = new URL(url, window.location.origin);

    if (target.origin === window.location.origin) visit(target.pathname + target.search + target.hash);
    else window.location.href = target.toString();
}
