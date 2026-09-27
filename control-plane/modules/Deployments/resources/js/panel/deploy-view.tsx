import {
    Avatar,
    Button,
    IconButton,
    LogViewer,
    PhaseTimeline,
    RelativeTime,
    SkeletonRows,
    StatusBadge,
    Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
    formatDuration,
    toast,
    type LogLine,
    type PhaseRow,
} from '@/components/kiln';
import { useEchoChannel } from '@/hooks/use-echo-channel';
import { errorMessage, requestJson } from '@/lib/http';
import { type ServicePanelContext } from '@/lib/registry';
import { AlertTriangle, ArrowLeft, Ban, Rocket, RotateCcw } from 'lucide-react';
import { useCallback, useEffect, useMemo, useReducer, useRef, useState } from 'react';
import { type OutputLine, type Step, type StepStatus, type Target } from '../types';
import { TERMINAL, deploy, deploymentsUrl, durationMs, firstLine, rollback, type DeploymentDetail } from './api';
import { CommitTag, triggerLabel } from './deployment-row';

const PHASES = [
    { id: 'build', label: 'Build' },
    { id: 'fetch', label: 'Fetch' },
    { id: 'prepare', label: 'Prepare' },
    { id: 'migrate', label: 'Migrate' },
    { id: 'activate', label: 'Activate' },
    { id: 'restart', label: 'Restart' },
    { id: 'healthcheck', label: 'Health' },
    { id: 'rollback', label: 'Rollback' },
];

const CELL_STATUS: Record<StepStatus, string> = {
    pending: 'pending',
    running: 'running',
    succeeded: 'succeeded',
    failed: 'failed',
    skipped: 'skipped',
};

/** Worst status of a phase's steps (failed > running > pending > succeeded > skipped). */
function phaseCell(steps: Step[]) {
    if (steps.length === 0) return undefined;
    const status =
        (['failed', 'running', 'pending', 'succeeded'] as StepStatus[]).find((candidate) => steps.some((step) => step.status === candidate)) ??
        'skipped';

    return { status: CELL_STATUS[status], durationMs: steps.reduce((sum, step) => sum + (step.duration_ms ?? 0), 0) || null };
}

function timelineRows(targets: Target[], globalSteps: Step[]): PhaseRow[] {
    const build = phaseCell(globalSteps.filter((step) => step.phase === 'build' || step.kind === 'build'));

    return targets.map((target) => ({
        id: target.id,
        label: (
            <span className="flex min-w-0 items-center gap-1.5">
                <span className="text-fg truncate font-mono">{target.server_name}</span>
                {target.role === 'leader' && targets.length > 1 && <span className="text-warning">★</span>}
            </span>
        ),
        cells: Object.fromEntries(
            PHASES.map((phase) => [phase.id, phase.id === 'build' ? build : phaseCell(target.steps.filter((step) => step.phase === phase.id))]),
        ),
    }));
}

function toLogLine(line: OutputLine): LogLine {
    return {
        text: line.data.replace(/\n$/, ''),
        time: new Date(line.at).toLocaleTimeString([], { hour12: false }),
        phase: [line.server ?? 'kiln', line.phase].filter(Boolean).join(' · '),
        level: line.stream === 'stderr' ? 'warning' : undefined,
    };
}

/** Live-ticking duration for running deployments. */
function useTick(active: boolean) {
    const [, tick] = useReducer((n: number) => n + 1, 0);
    useEffect(() => {
        if (!active) return;
        const timer = window.setInterval(tick, 1000);

        return () => window.clearInterval(timer);
    }, [active]);
}

/**
 * §5.2 Deploy view: header (status, commit, trigger, duration, Redeploy / Rollback / Cancel), per-server phase
 * timeline, and Build / Deploy logs streamed live (Reverb, polling fallback).
 */
export function DeployView({ ctx, deploymentId }: { ctx: ServicePanelContext; deploymentId: string }) {
    const siteId = ctx.service.ref_id;
    const [detail, setDetail] = useState<Omit<DeploymentDetail, 'lines'> | null>(null);
    const [lines, setLines] = useState<OutputLine[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const [logTab, setLogTab] = useState<'build' | 'deploy' | null>(null);
    const lastSeq = useRef(0);
    const url = `${deploymentsUrl(siteId)}/${deploymentId}`;

    const append = useCallback((incoming: OutputLine[]) => {
        const fresh = incoming.filter((line) => line.seq > lastSeq.current).sort((a, b) => a.seq - b.seq);
        if (fresh.length === 0) return;
        lastSeq.current = fresh[fresh.length - 1].seq;
        setLines((current) => [...current, ...fresh]);
    }, []);

    useEffect(() => {
        let cancelled = false;
        lastSeq.current = 0;
        setLines([]);
        requestJson<{ data: DeploymentDetail }>(url)
            .then((body) => {
                if (cancelled) return;
                const { lines: initial, ...rest } = body.data;
                setDetail(rest);
                append(initial);
            })
            .catch((e: unknown) => !cancelled && setError(errorMessage(e, 'Could not load the deployment')));

        return () => {
            cancelled = true;
        };
    }, [url, append]);

    const refresh = useCallback(async () => {
        try {
            const body = await requestJson<{ data: Omit<DeploymentDetail, 'can'> }>(`${url}/state?after=${lastSeq.current}`);
            setDetail((current) =>
                current ? { ...current, deployment: body.data.deployment, targets: body.data.targets, steps: body.data.steps } : current,
            );
            append(body.data.lines);
        } catch {
            // Transient: the next tick retries.
        }
    }, [url, append]);

    const live = useEchoChannel<{ lines?: OutputLine[] }>(
        `deployments.${deploymentId}`,
        ['deployment.updated', 'deployment.output'],
        (event, payload) => {
            if (event === 'deployment.output' && payload.lines) append(payload.lines);
            else void refresh();
        },
    );

    const deployment = detail?.deployment;
    const terminal = deployment ? TERMINAL.includes(deployment.status) : false;
    useTick(Boolean(deployment && !terminal));

    useEffect(() => {
        if (!deployment || terminal) return;
        const timer = window.setInterval(() => void refresh(), live ? 10000 : 2000);

        return () => window.clearInterval(timer);
    }, [deployment, terminal, live, refresh]);

    // The card on the canvas follows the deployment; refresh it when this one finishes.
    const wasTerminal = useRef(terminal);
    useEffect(() => {
        if (terminal && !wasTerminal.current) ctx.refresh();
        wasTerminal.current = terminal;
    }, [terminal, ctx]);

    const buildLines = useMemo(() => lines.filter((line) => line.phase === 'build').map(toLogLine), [lines]);
    const deployLines = useMemo(() => lines.filter((line) => line.phase !== 'build').map(toLogLine), [lines]);

    if (error) return <p className="text-danger text-sm">{error}</p>;
    if (!detail || !deployment) return <SkeletonRows rows={8} />;

    const activeLogTab =
        logTab ?? (deployment.status === 'building' || deployment.status === 'queued' || deployLines.length === 0 ? 'build' : 'deploy');
    const duration = durationMs(deployment.started_at, deployment.finished_at);
    const rows = timelineRows(detail.targets, detail.steps);
    const usedPhases = PHASES.filter((phase) => phase.id !== 'rollback' || rows.some((row) => row.cells.rollback));

    const act = async (id: string, run: () => Promise<void>) => {
        setBusy(id);
        try {
            await run();
        } finally {
            setBusy(null);
        }
    };

    const cancel = () =>
        act('cancel', async () => {
            try {
                await requestJson(`${url}/cancel`, 'POST', {});
                toast.success(`Deployment #${deployment.number} cancelled`);
                await refresh();
                ctx.refresh();
            } catch (e) {
                toast.error('Could not cancel', errorMessage(e));
            }
        });

    return (
        <div className="grid gap-5" data-testid="deploy-view">
            <div className="flex flex-wrap items-start gap-3">
                <IconButton label="Back to deployments" icon={<ArrowLeft />} size="sm" onClick={() => ctx.open('deployments')} />
                <div className="grid min-w-0 flex-1 gap-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="text-fg text-sm font-semibold">Deployment #{deployment.number}</h3>
                        <StatusBadge status={deployment.status} />
                        {deployment.rolled_back && <StatusBadge status="degraded" label="Rolled back" />}
                    </div>
                    <p className="text-fg truncate text-sm">{firstLine(deployment.message) ?? triggerLabel(deployment.trigger)}</p>
                    <div className="text-fg-muted flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                        <CommitTag sha={deployment.commit} branch={deployment.branch} />
                        {deployment.author && (
                            <span className="flex items-center gap-1.5">
                                <Avatar name={deployment.author} size="xs" /> {deployment.author}
                            </span>
                        )}
                        <span>{triggerLabel(deployment.trigger)}</span>
                        {deployment.strategy && <span>{deployment.strategy}</span>}
                        <RelativeTime value={deployment.started_at ?? deployment.created_at} />
                        {duration !== null && <span className="tabular">{formatDuration(duration)}</span>}
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-1.5">
                    {detail.can.cancel && !terminal && (
                        <Button size="sm" variant="ghost" icon={<Ban />} loading={busy === 'cancel'} onClick={() => void cancel()}>
                            Cancel
                        </Button>
                    )}
                    {detail.can.rollback && deployment.release_id && (
                        <Button
                            size="sm"
                            icon={<RotateCcw />}
                            loading={busy === 'rollback'}
                            onClick={() => void act('rollback', () => rollback(ctx, deployment.release_id!))}
                        >
                            Rollback
                        </Button>
                    )}
                    {detail.can.redeploy && (
                        <Button
                            size="sm"
                            icon={<Rocket />}
                            loading={busy === 'redeploy'}
                            onClick={() => void act('redeploy', () => deploy(ctx, { commit: deployment.commit, branch: deployment.branch }))}
                        >
                            Redeploy
                        </Button>
                    )}
                </div>
            </div>

            {deployment.error && (
                <div role="alert" className="border-danger/40 bg-danger-soft flex gap-2.5 rounded-lg border px-3 py-2.5 text-sm">
                    <AlertTriangle className="text-danger mt-0.5 size-4 shrink-0" aria-hidden />
                    <div className="grid gap-0.5">
                        <span className="text-fg font-medium">{deployment.status === 'cancelled' ? 'Cancelled' : 'Deployment failed'}</span>
                        <span className="text-fg-muted">
                            {deployment.error}
                            {deployment.rolled_back && ' Servers that had switched were rolled back to the previous release.'}
                        </span>
                    </div>
                </div>
            )}

            {rows.length > 0 ? (
                <PhaseTimeline phases={usedPhases} rows={rows} />
            ) : (
                <p className="text-fg-faint text-xs">
                    {deployment.status === 'queued' ? 'Waiting for the deployment ahead of this one.' : 'No servers in this deployment.'}
                </p>
            )}

            <Tabs value={activeLogTab} onValueChange={(value) => setLogTab(value as 'build' | 'deploy')} className="grid gap-3">
                <TabsList>
                    <TabsTrigger value="build" badge={buildLines.length || undefined}>
                        Build logs
                    </TabsTrigger>
                    <TabsTrigger value="deploy" badge={deployLines.length || undefined}>
                        Deploy logs
                    </TabsTrigger>
                </TabsList>
                <TabsContent value="build">
                    <LogViewer
                        lines={buildLines}
                        label={`Build log of deployment #${deployment.number}`}
                        filename={`deployment-${deployment.number}-build.log`}
                        streaming={!terminal}
                        height="min(56vh, 520px)"
                        emptyText={
                            deployment.status === 'queued'
                                ? 'Waiting to start…'
                                : 'No build output (the release reuses an existing build or builds on the servers).'
                        }
                    />
                </TabsContent>
                <TabsContent value="deploy">
                    <LogViewer
                        lines={deployLines}
                        label={`Deploy log of deployment #${deployment.number}`}
                        filename={`deployment-${deployment.number}-deploy.log`}
                        streaming={!terminal}
                        height="min(56vh, 520px)"
                        emptyText={terminal ? 'No deploy output.' : 'Waiting for the servers…'}
                    />
                </TabsContent>
            </Tabs>
        </div>
    );
}
