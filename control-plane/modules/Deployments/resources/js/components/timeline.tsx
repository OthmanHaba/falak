import { cn } from '@/lib/utils';
import { type Phase, PHASES, type Step, type StepStatus, type Target } from '../types';
import { duration, StepIcon } from './deploy-ui';

/** Worst status of the steps of one phase (failed > running > pending > succeeded > skipped). */
function phaseStatus(steps: Step[]): StepStatus | undefined {
    if (steps.length === 0) {
        return undefined;
    }

    for (const status of ['failed', 'running', 'pending', 'succeeded'] as StepStatus[]) {
        if (steps.some((step) => step.status === status)) {
            return status;
        }
    }

    return 'skipped';
}

interface Props {
    targets: Target[];
    selected: { server: string | null; phase: Phase | null };
    onSelect: (server: string | null, phase: Phase | null) => void;
}

/**
 * Per-server phase timeline: one row per target, one cell per phase. Clicking a cell filters the output.
 */
export function PhaseTimeline({ targets, selected, onSelect }: Props) {
    const phases = PHASES.filter((phase) => targets.some((target) => target.steps.some((step) => step.phase === phase)));

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="text-muted-foreground text-left text-xs">
                        <th className="py-2 pr-4 font-medium">Server</th>
                        {phases.map((phase) => (
                            <th key={phase} className="px-2 py-2 font-medium capitalize">
                                <button
                                    type="button"
                                    className={cn('hover:text-foreground', selected.phase === phase && 'text-foreground underline')}
                                    onClick={() => onSelect(selected.server, selected.phase === phase ? null : phase)}
                                >
                                    {phase === 'healthcheck' ? 'health' : phase}
                                </button>
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {targets.map((target) => (
                        <tr key={target.id} className="border-t">
                            <td className="py-2 pr-4">
                                <button
                                    type="button"
                                    onClick={() => onSelect(selected.server === target.server_id ? null : target.server_id, selected.phase)}
                                    className={cn('text-left', selected.server === target.server_id && 'font-semibold underline')}
                                >
                                    {target.server_name}
                                </button>
                                <div className="text-muted-foreground text-xs">
                                    {target.role}
                                    {target.batch > 0 || targets.some((t) => t.batch > 0) ? ` · batch ${target.batch + 1}` : ''}
                                    {target.status === 'rolled_back' ? ' · rolled back' : ''}
                                </div>
                            </td>
                            {phases.map((phase) => {
                                const steps = target.steps.filter((step) => step.phase === phase);
                                const status = phaseStatus(steps);
                                const total = steps.reduce((sum, step) => sum + (step.duration_ms ?? 0), 0);
                                const failed = steps.find((step) => step.status === 'failed');

                                return (
                                    <td key={phase} className="px-2 py-2 align-top">
                                        {status ? (
                                            <button
                                                type="button"
                                                title={failed?.error ?? steps.map((step) => `${step.label}: ${step.status}`).join('\n')}
                                                onClick={() => onSelect(target.server_id, phase)}
                                                className="flex items-center gap-1.5"
                                            >
                                                <StepIcon status={status} />
                                                <span className="text-muted-foreground text-xs">{total > 0 ? duration(total) : ''}</span>
                                            </button>
                                        ) : (
                                            <span className="text-muted-foreground/40">—</span>
                                        )}
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
