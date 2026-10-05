import { cn } from '@/lib/utils';
import { Check, Minus, X } from 'lucide-react';
import { type ReactNode } from 'react';
import { StatusDot, statusSpec } from './status';

export interface Step {
    id: string;
    label: string;
    /** active|succeeded|failed|queued|skipped|running… (status language). */
    status: string;
    detail?: ReactNode;
}

function StepMarker({ status }: { status: string }) {
    const { tone, pulse } = statusSpec(status);
    const base = 'flex size-5 shrink-0 items-center justify-center rounded-full [&_svg]:size-3';

    if (tone === 'success')
        return (
            <span className={cn(base, 'bg-success-soft text-success')}>
                <Check strokeWidth={3} aria-hidden />
            </span>
        );
    if (tone === 'danger')
        return (
            <span className={cn(base, 'bg-danger-soft text-danger')}>
                <X strokeWidth={3} aria-hidden />
            </span>
        );
    if (tone === 'faint' && status !== 'pending')
        return (
            <span className={cn(base, 'bg-faint-soft text-fg-faint')}>
                <Minus strokeWidth={3} aria-hidden />
            </span>
        );

    return (
        <span className={cn(base, 'border-border bg-surface-2 border')}>
            <StatusDot status={status} pulse={pulse} size="sm" />
        </span>
    );
}

/** Vertical/horizontal stepper for a linear process (provisioning, create wizards). */
export function Stepper({
    steps,
    orientation = 'vertical',
    className,
}: {
    steps: Step[];
    orientation?: 'vertical' | 'horizontal';
    className?: string;
}) {
    return (
        <ol className={cn(orientation === 'vertical' ? 'grid gap-0' : 'flex flex-wrap items-center gap-2', className)}>
            {steps.map((step, index) => (
                <li
                    key={step.id}
                    className={cn('relative flex gap-3', orientation === 'vertical' ? 'pb-4 last:pb-0' : 'items-center')}
                    aria-label={`${step.label}: ${statusSpec(step.status).label}`}
                >
                    {orientation === 'vertical' && index < steps.length - 1 && (
                        <span className="bg-border absolute top-6 bottom-1 left-2.5 w-px" aria-hidden />
                    )}
                    <StepMarker status={step.status} />
                    <div className="grid min-w-0 gap-0.5">
                        <span className="text-fg text-sm">{step.label}</span>
                        {step.detail && <span className="text-fg-faint text-xs">{step.detail}</span>}
                    </div>
                    {orientation === 'horizontal' && index < steps.length - 1 && <span className="bg-border h-px w-6" aria-hidden />}
                </li>
            ))}
        </ol>
    );
}

export interface PhaseCell {
    status: string;
    /** Duration in ms. */
    durationMs?: number | null;
}

export interface PhaseRow {
    id: string;
    label: ReactNode;
    /** Keyed by phase id. Missing = not applicable (e.g. Migrate on non-leader). */
    cells: Record<string, PhaseCell | undefined>;
}

export function formatDuration(ms: number | null | undefined): string {
    if (ms === null || ms === undefined) return '';
    if (ms < 1000) return `${ms}ms`;
    const seconds = ms / 1000;
    if (seconds < 60) return `${seconds.toFixed(seconds < 10 ? 1 : 0)}s`;

    return `${Math.floor(seconds / 60)}m ${Math.round(seconds % 60)}s`;
}

const CELL_TONE = {
    success: 'border-success/30 bg-success-soft text-success',
    warning: 'border-warning/40 bg-warning-soft text-warning',
    info: 'border-info/30 bg-info-soft text-info',
    danger: 'border-danger/40 bg-danger-soft text-danger',
    faint: 'border-border bg-surface-2 text-fg-faint',
} as const;

/**
 * §5.2 phase timeline: rows = servers, columns = phases (Build → Fetch → Prepare → Migrate → Activate → Restart → Health),
 * cells = phase status with duration.
 */
export function PhaseTimeline({ phases, rows, className }: { phases: { id: string; label: string }[]; rows: PhaseRow[]; className?: string }) {
    return (
        <div className={cn('overflow-x-auto', className)}>
            <table className="w-full border-separate border-spacing-1 text-xs">
                <thead>
                    <tr>
                        <th scope="col" className="text-fg-faint w-32 text-left font-medium">
                            <span className="sr-only">Server</span>
                        </th>
                        {phases.map((phase) => (
                            <th key={phase.id} scope="col" className="text-fg-faint min-w-20 px-1 text-left font-medium">
                                {phase.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.id}>
                            <th scope="row" className="text-fg-muted truncate pr-2 text-left font-normal">
                                {row.label}
                            </th>
                            {phases.map((phase) => {
                                const cell = row.cells[phase.id];
                                if (!cell) {
                                    return (
                                        <td
                                            key={phase.id}
                                            className="border-border h-7 rounded-md border border-dashed"
                                            aria-label={`${phase.label}: not applicable`}
                                        />
                                    );
                                }
                                const spec = statusSpec(cell.status);

                                return (
                                    <td
                                        key={phase.id}
                                        className={cn('h-7 rounded-md border px-2', CELL_TONE[spec.tone])}
                                        aria-label={`${phase.label}: ${spec.label}${cell.durationMs ? `, ${formatDuration(cell.durationMs)}` : ''}`}
                                    >
                                        <span className="flex items-center gap-1.5">
                                            <StatusDot status={cell.status} size="sm" />
                                            <span className="tabular">{formatDuration(cell.durationMs) || spec.label}</span>
                                        </span>
                                    </td>
                                );
                            })}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
