import { Tag } from '@/components/falak/tag';
import { ShieldAlert, ShieldCheck } from 'lucide-react';

export type CheckStatus = 'pass' | 'warn' | 'fail' | 'info';
export type Severity = 'critical' | 'high' | 'medium' | 'low' | 'info';

export interface AuditSummary {
    id: string;
    score: number | null;
    production_ready: boolean;
    counts: Partial<Record<CheckStatus | Severity, number>>;
    trigger: string;
    duration_ms: number | null;
    ran_at: string | null;
}

/** Ring colour by score band: 90+ good, 70+ needs attention, below that at risk. */
function tone(score: number): string {
    if (score >= 90) return 'var(--success)';
    if (score >= 70) return 'var(--warning)';

    return 'var(--danger)';
}

/** The 0-100 baseline score as a ring with the number in the middle. */
export function ScoreRing({ score, size = 88 }: { score: number | null; size?: number }) {
    const stroke = size >= 64 ? 8 : 4;
    const r = (size - stroke) / 2;
    const c = 2 * Math.PI * r;
    const value = score ?? 0;

    return (
        <svg
            width={size}
            height={size}
            viewBox={`0 0 ${size} ${size}`}
            role="img"
            aria-label={score === null ? 'Not audited yet' : `Security score ${score} of 100`}
        >
            <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="var(--border)" strokeWidth={stroke} />
            {score !== null && (
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={r}
                    fill="none"
                    stroke={tone(value)}
                    strokeWidth={stroke}
                    strokeLinecap="round"
                    strokeDasharray={`${(value / 100) * c} ${c}`}
                    transform={`rotate(-90 ${size / 2} ${size / 2})`}
                />
            )}
            <text
                x="50%"
                y="50%"
                dominantBaseline="central"
                textAnchor="middle"
                className="tabular text-fg fill-current font-semibold"
                fontSize={size >= 64 ? size / 3.4 : size / 2.6}
            >
                {score ?? '–'}
            </text>
        </svg>
    );
}

export function ReadyBadge({ ready }: { ready: boolean }) {
    return ready ? (
        <Tag tone="success" icon={<ShieldCheck className="size-3.5" />}>
            Production ready
        </Tag>
    ) : (
        <Tag tone="danger" icon={<ShieldAlert className="size-3.5" />}>
            Not production ready
        </Tag>
    );
}

const SEVERITY_TONE: Record<Severity, 'danger' | 'warning' | 'info' | 'faint'> = {
    critical: 'danger',
    high: 'danger',
    medium: 'warning',
    low: 'info',
    info: 'faint',
};

export function SeverityTag({ severity }: { severity: Severity }) {
    return <Tag tone={SEVERITY_TONE[severity]}>{severity}</Tag>;
}

const STATUS_LABEL: Record<CheckStatus, { label: string; tone: 'success' | 'warning' | 'danger' | 'faint' }> = {
    pass: { label: 'Pass', tone: 'success' },
    warn: { label: 'Warning', tone: 'warning' },
    fail: { label: 'Fail', tone: 'danger' },
    info: { label: 'Info', tone: 'faint' },
};

export function StatusTag({ status }: { status: CheckStatus }) {
    const s = STATUS_LABEL[status];

    return <Tag tone={s.tone}>{s.label}</Tag>;
}
