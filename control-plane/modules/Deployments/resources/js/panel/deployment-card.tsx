import {
    Avatar,
    Button,
    Menu,
    PhaseTimeline,
    RelativeTime,
    SkeletonRows,
    StatusPill,
    formatDuration,
    type MenuAction,
    type StatusTone,
} from '@/components/falak';
import { type ServicePanelContext } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { Check, ChevronDown, Eye, GitCommitHorizontal, Hourglass, Loader2, RotateCcw, Terminal, Webhook, X, type LucideIcon } from 'lucide-react';
import { useId, useState } from 'react';
import { type Deployment } from '../types';
import { durationMs, firstLine, type RunningDeployment } from './api';
import { CommitTag, triggerLabel } from './deployment-row';
import { PHASES, timelineRows, useDeployment } from './use-deployment';
import { WaitingNotice } from './waiting-notice';

/** How a deployment was started, as a short phrase after its age: "12 minutes ago via git push". */
export function viaLabel(trigger: string): string {
    return (
        {
            manual: 'manually',
            push: 'via git push',
            hook: 'via deploy hook',
            api: 'via API',
            rollback: 'as a rollback',
            schedule: 'on schedule',
        }[trigger] ?? `via ${triggerLabel(trigger).toLowerCase()}`
    );
}

const TRIGGER_ICONS: Record<string, LucideIcon> = { push: GitCommitHorizontal, hook: Webhook, api: Terminal, rollback: RotateCcw };

/** Author avatar with a small badge for how the deployment was triggered. */
export function SourceAvatar({ deployment }: { deployment: Pick<Deployment, 'author' | 'trigger'> }) {
    const Icon = TRIGGER_ICONS[deployment.trigger] ?? Terminal;

    return (
        <span className="relative hidden shrink-0 sm:inline-flex">
            <Avatar name={deployment.author ?? triggerLabel(deployment.trigger)} size="md" />
            <span
                className="border-surface-1 bg-surface-3 text-fg-muted absolute -right-1 -bottom-1 flex size-4 items-center justify-center rounded-full border-2 [&_svg]:size-2.5"
                aria-hidden
            >
                <Icon strokeWidth={2.5} />
            </span>
        </span>
    );
}

type Mode = 'live' | 'running' | 'waiting' | 'failed';

const MODES: Record<Mode, { tone: StatusTone; card: string; footer: string }> = {
    live: { tone: 'success', card: 'border-success/35 bg-success-soft/35', footer: 'border-success/20 text-success hover:bg-success-soft/60' },
    running: { tone: 'warning', card: 'border-warning/35 bg-warning-soft/30', footer: 'border-warning/20 text-warning hover:bg-warning-soft/50' },
    waiting: { tone: 'info', card: 'border-info/35 bg-info-soft/30', footer: 'border-info/20 text-info hover:bg-info-soft/50' },
    failed: { tone: 'danger', card: 'border-danger/35 bg-danger-soft/30', footer: 'border-danger/20 text-danger hover:bg-danger-soft/50' },
};

function footerLabel(mode: Mode, deployment: Deployment | RunningDeployment): { icon: LucideIcon; text: string; spin?: boolean } {
    switch (mode) {
        case 'live':
            return { icon: Check, text: 'Deployment successful' };
        case 'waiting':
            return { icon: Hourglass, text: 'Waiting for servers to finish preparing' };
        case 'failed':
            return { icon: X, text: deployment.status === 'cancelled' ? 'Deployment cancelled' : 'Deployment failed' };
        default:
            return {
                icon: Loader2,
                spin: true,
                text:
                    deployment.status === 'building'
                        ? 'Building'
                        : deployment.status === 'queued'
                          ? 'Queued'
                          : `Deploying${deployment.phase ? ` · ${deployment.phase}` : ''}`,
            };
    }
}

/** Phase timeline of one deployment, fetched when its card is expanded. */
function Phases({ ctx, deployment }: { ctx: ServicePanelContext; deployment: Deployment | RunningDeployment }) {
    const { detail } = useDeployment(ctx.service.ref_id, deployment.id);
    if (!detail) return <SkeletonRows rows={2} />;
    const rows = timelineRows(detail.targets, detail.steps, (target) => <span className="text-fg truncate font-mono">{target.server_name}</span>);
    const phases = PHASES.filter((phase) => phase.id !== 'rollback' || rows.some((row) => row.cells.rollback));

    return rows.length > 0 ? (
        <PhaseTimeline phases={phases} rows={rows} />
    ) : (
        <p className="text-fg-faint text-xs">No servers in this deployment yet.</p>
    );
}

/**
 * The featured deployment card (§5.1): the live release (success tint, ACTIVE), or the deployment running / waiting /
 * failed right now. Title = commit message, subtitle = age + trigger, "View logs" opens the stacked deployment panel;
 * the attached footer expands to the per-server phase timeline.
 */
export function DeploymentCard({
    ctx,
    deployment,
    mode,
    label,
    actions,
    selected = false,
}: {
    ctx: ServicePanelContext;
    deployment: Deployment | RunningDeployment;
    mode: Mode;
    /** Pill text (ACTIVE, BUILDING, …). */
    label: string;
    actions: MenuAction[];
    selected?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const panelId = useId();
    const style = MODES[mode];
    const footer = footerLabel(mode, deployment);
    const duration = durationMs(deployment.started_at, deployment.finished_at);

    return (
        <section
            aria-label={`${label} deployment #${deployment.number}`}
            data-testid={`deployment-card-${mode}`}
            className={cn('animate-rise-in overflow-hidden rounded-xl border transition-shadow', style.card, selected && 'ring-primary/60 ring-2')}
        >
            <div className="flex items-center gap-3 px-3.5 py-3 sm:gap-4 sm:px-4">
                <StatusPill status={deployment.status} tone={style.tone} label={label} className="justify-center sm:w-[4.75rem]" />
                <SourceAvatar deployment={deployment} />
                <div className="grid min-w-0 flex-1 gap-0.5">
                    <p className="text-fg truncate text-sm font-medium">{firstLine(deployment.message) ?? triggerLabel(deployment.trigger)}</p>
                    <p className="text-fg-muted flex min-w-0 flex-wrap items-center gap-x-2 text-xs">
                        <span>
                            <RelativeTime value={deployment.finished_at ?? deployment.started_at ?? deployment.created_at} />{' '}
                            {viaLabel(deployment.trigger)}
                        </span>
                        <span className="hidden items-center sm:inline-flex">
                            <CommitTag sha={deployment.commit} branch={deployment.branch} />
                        </span>
                        {duration !== null && (
                            <span className="tabular text-fg-faint hidden sm:inline">
                                {mode === 'running' && !deployment.finished_at ? 'running ' : ''}
                                {formatDuration(duration)}
                            </span>
                        )}
                    </p>
                </div>
                <Button size="sm" variant="secondary" className="shrink-0" onClick={() => ctx.openLayer('deployment', deployment.id)}>
                    View logs
                </Button>
                {actions.length > 0 && <Menu actions={actions} label={`Deployment #${deployment.number} actions`} />}
            </div>
            {mode === 'waiting' && (
                <div className="px-3.5 pb-3 sm:px-4">
                    <WaitingNotice deployment={deployment} compact />
                </div>
            )}
            {deployment.watch?.status === 'watching' && (
                <p className="text-info flex items-center gap-1.5 px-3.5 pb-3 text-xs font-medium sm:px-4" data-testid="watch-card-line">
                    <Eye className="size-3.5 animate-pulse" aria-hidden />
                    Watching for {Math.max(1, Math.ceil(deployment.watch.remaining_s / 60))} more min —{' '}
                    {deployment.watch.on_trigger === 'alert_only' ? 'alerts' : 'rolls back'} if it turns unhealthy
                </p>
            )}
            <button
                type="button"
                aria-expanded={open}
                aria-controls={panelId}
                onClick={() => setOpen((value) => !value)}
                className={cn('flex w-full items-center gap-2.5 border-t px-3.5 py-2 text-left text-sm transition-colors sm:px-4', style.footer)}
            >
                <span className="flex shrink-0 justify-center sm:w-[4.75rem]">
                    <footer.icon className={cn('size-4', footer.spin && 'animate-spin')} aria-hidden />
                </span>
                <span className="min-w-0 flex-1 truncate font-medium">{footer.text}</span>
                <ChevronDown className={cn('size-4 shrink-0 transition-transform duration-200', open && 'rotate-180')} aria-hidden />
            </button>
            {open && (
                <div id={panelId} className="border-border/60 bg-surface-1 animate-tab-in border-t px-3.5 py-3.5 sm:px-4">
                    {deployment.error && <p className="text-danger mb-3 text-sm">{deployment.error}</p>}
                    <Phases ctx={ctx} deployment={deployment} />
                </div>
            )}
        </section>
    );
}

/** A quieter card for the deployment history (superseded, failed, cancelled, queued). */
export function HistoryCard({
    ctx,
    deployment,
    actions,
    selected = false,
}: {
    ctx: ServicePanelContext;
    deployment: Deployment;
    actions: MenuAction[];
    selected?: boolean;
}) {
    const duration = durationMs(deployment.started_at, deployment.finished_at);
    const [status, label, tone]: [string, string, StatusTone | undefined] = deployment.rolled_back
        ? ['degraded', 'Rolled back', 'warning']
        : deployment.partial && deployment.status === 'succeeded'
          ? ['degraded', `Partial: ${deployment.partial.services.join(', ')}`, 'warning']
          : deployment.status === 'succeeded'
            ? ['removed', 'Removed', 'faint']
            : [deployment.status, deployment.status === 'waiting' ? 'Waiting' : '', undefined];

    return (
        <div
            className={cn(
                'group border-border bg-surface-1 hover:border-border-strong hover:bg-surface-2/60 relative flex items-center gap-3 rounded-xl border px-3.5 py-3 transition-colors sm:gap-4 sm:px-4',
                selected && 'border-primary/60',
            )}
            data-testid="deployment-history-card"
        >
            <StatusPill status={status} label={label || undefined} tone={tone} className="justify-center sm:w-[4.75rem]" />
            <SourceAvatar deployment={deployment} />
            <button
                type="button"
                onClick={() => ctx.openLayer('deployment', deployment.id)}
                className="grid min-w-0 flex-1 gap-0.5 text-left after:absolute after:inset-0 after:rounded-xl"
                aria-label={`Deployment #${deployment.number}: ${firstLine(deployment.message) ?? triggerLabel(deployment.trigger)}`}
            >
                <span className="text-fg truncate text-sm">{firstLine(deployment.message) ?? triggerLabel(deployment.trigger)}</span>
                <span className="text-fg-muted flex min-w-0 flex-wrap items-center gap-x-2 text-xs">
                    <span>
                        <RelativeTime value={deployment.created_at} /> {viaLabel(deployment.trigger)}
                    </span>
                    <span className="text-fg-faint tabular">#{deployment.number}</span>
                    {duration !== null && deployment.finished_at && (
                        <span className="text-fg-faint tabular hidden sm:inline">{formatDuration(duration)}</span>
                    )}
                </span>
            </button>
            {actions.length > 0 && (
                <span className="relative z-10">
                    <Menu actions={actions} label={`Deployment #${deployment.number} actions`} />
                </span>
            )}
        </div>
    );
}
