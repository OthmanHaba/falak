import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useEchoChannel } from '@/hooks/use-echo-channel';
import SiteLayout from '@/layouts/site-layout';
import { Link, router } from '@inertiajs/react';
import { AlertTriangle, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { between, Commit, DeploymentStatusBadge, StepIcon, TERMINAL, when } from '../components/deploy-ui';
import { OutputView } from '../components/output-view';
import { PhaseTimeline } from '../components/timeline';
import { type Deployment, type OutputLine, type Phase, type SitePageProps, type Step, type Target } from '../types';

interface Props extends SitePageProps {
    deployment: Deployment;
    targets: Target[];
    steps: Step[];
    lines: OutputLine[];
    can: { cancel: boolean };
}

interface State {
    deployment: Deployment;
    targets: Target[];
    steps: Step[];
    lines: OutputLine[];
}

export default function Show({ site, deployment: initial, targets: initialTargets, steps: initialSteps, lines: initialLines, can }: Props) {
    const [state, setState] = useState<Omit<State, 'lines'>>({ deployment: initial, targets: initialTargets, steps: initialSteps });
    const [lines, setLines] = useState<OutputLine[]>(initialLines);
    const [filter, setFilter] = useState<{ server: string | null; phase: Phase | null }>({ server: null, phase: null });
    const lastSeq = useRef(initialLines.length ? initialLines[initialLines.length - 1].seq : 0);
    const { deployment, targets, steps } = state;
    const terminal = TERMINAL.includes(deployment.status);

    const append = useCallback((incoming: OutputLine[]) => {
        const fresh = incoming.filter((line) => line.seq > lastSeq.current).sort((a, b) => a.seq - b.seq);

        if (fresh.length > 0) {
            lastSeq.current = fresh[fresh.length - 1].seq;
            setLines((current) => [...current, ...fresh]);
        }
    }, []);

    const refresh = useCallback(async () => {
        const response = await fetch(`/sites/${site.id}/deployments/${initial.id}/state?after=${lastSeq.current}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (response.ok) {
            const body = (await response.json()) as { data: State };
            setState({ deployment: body.data.deployment, targets: body.data.targets, steps: body.data.steps });
            append(body.data.lines);
        }
    }, [site.id, initial.id, append]);

    const live = useEchoChannel<{ lines?: OutputLine[] }>(
        `deployments.${initial.id}`,
        ['deployment.updated', 'deployment.output'],
        (event, payload) => {
            if (event === 'deployment.output' && payload.lines) {
                append(payload.lines);
            } else {
                void refresh();
            }
        },
    );

    useEffect(() => {
        if (terminal) {
            return;
        }

        const timer = window.setInterval(() => void refresh(), live ? 10000 : 2000);

        return () => window.clearInterval(timer);
    }, [live, terminal, refresh]);

    const build = steps.find((step) => step.kind === 'build');

    return (
        <SiteLayout
            site={site}
            title={`Deployment #${deployment.number}`}
            actions={
                can.cancel && !terminal && (deployment.status === 'queued' || deployment.status === 'building') ? (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => router.post(`/sites/${site.id}/deployments/${deployment.id}/cancel`, {}, { onSuccess: () => void refresh() })}
                    >
                        <X className="size-4" /> Cancel
                    </Button>
                ) : undefined
            }
        >
            <Card>
                <CardHeader>
                    <CardTitle className="flex flex-wrap items-center gap-3">
                        <Link href={`/sites/${site.id}/deployments`} className="text-muted-foreground hover:text-foreground text-sm font-normal">
                            Deployments
                        </Link>
                        <span>#{deployment.number}</span>
                        <DeploymentStatusBadge status={deployment.status} rolledBack={deployment.rolled_back} />
                    </CardTitle>
                    <CardDescription className="flex flex-wrap gap-x-4 gap-y-1">
                        <span>
                            <Commit sha={deployment.commit} branch={deployment.branch} /> {deployment.message?.split('\n')[0]}
                        </span>
                        {deployment.author && <span>by {deployment.author}</span>}
                        <span className="capitalize">{deployment.trigger}</span>
                        {deployment.strategy && <span>{deployment.strategy}</span>}
                        <span>started {when(deployment.started_at)}</span>
                        {deployment.started_at && <span>{between(deployment.started_at, deployment.finished_at)}</span>}
                        {deployment.release_id && <span className="font-mono">release {deployment.release_id.toUpperCase()}</span>}
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    {deployment.error && (
                        <Alert variant="destructive">
                            <AlertTriangle className="size-4" />
                            <AlertTitle>{deployment.status === 'cancelled' ? 'Cancelled' : 'Deployment failed'}</AlertTitle>
                            <AlertDescription>
                                {deployment.error}
                                {deployment.rolled_back && ' Servers that had switched were rolled back to the previous release.'}
                            </AlertDescription>
                        </Alert>
                    )}
                    {build && (
                        <div className="flex items-center gap-2 text-sm">
                            <StepIcon status={build.status} />
                            <button
                                type="button"
                                className="hover:underline"
                                onClick={() => setFilter({ server: null, phase: filter.phase === 'build' ? null : 'build' })}
                            >
                                Build
                            </button>
                            {build.build_id && (
                                <Link href={`/builds/${build.build_id}`} className="text-muted-foreground text-xs hover:underline">
                                    view build
                                </Link>
                            )}
                            {build.error && <span className="text-sm text-red-600">{build.error}</span>}
                        </div>
                    )}
                    {targets.length > 0 && (
                        <PhaseTimeline targets={targets} selected={filter} onSelect={(server, phase) => setFilter({ server, phase })} />
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader className="flex flex-row items-center justify-between">
                    <div>
                        <CardTitle>Output</CardTitle>
                        <CardDescription>
                            {filter.server ? targets.find((t) => t.server_id === filter.server)?.server_name : 'All servers'} ·{' '}
                            {filter.phase ?? 'all phases'}
                        </CardDescription>
                    </div>
                    {(filter.server || filter.phase) && (
                        <Button variant="ghost" size="sm" onClick={() => setFilter({ server: null, phase: null })}>
                            Clear filter
                        </Button>
                    )}
                </CardHeader>
                <CardContent>
                    <OutputView lines={lines} server={filter.server} phase={filter.phase} />
                </CardContent>
            </Card>
        </SiteLayout>
    );
}
