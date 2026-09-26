import { CommandLog } from '@/components/command-log';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useEchoChannel } from '@/hooks/use-echo-channel';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { ChevronDown, ChevronRight, RotateCcw } from 'lucide-react';
import { Fragment, useCallback, useEffect, useState } from 'react';
import { formatDuration, RunStatusBadge, ScriptBlock } from '../components/run-ui';
import { type RunStatus, type RunSummary, type RunTargetRow } from '../types';

interface RunDetails extends RunSummary {
    script: string;
    timeout_s: number;
    env_keys: string[];
}

interface Props {
    run: RunDetails;
    targets: RunTargetRow[];
    can: { run: boolean; viewOutput: boolean };
}

interface LivePayload {
    run_id: string;
    status: RunStatus;
    targets: Omit<RunTargetRow, 'server_name' | 'started_at' | 'finished_at'>[];
}

const FINISHED: RunStatus[] = ['succeeded', 'failed', 'partial'];

export default function RunShow({ run, targets: initialTargets, can }: Props) {
    const [status, setStatus] = useState<RunStatus>(run.status);
    const [targets, setTargets] = useState<RunTargetRow[]>(initialTargets);
    const [expanded, setExpanded] = useState<Set<string>>(() => new Set(initialTargets.length === 1 ? [initialTargets[0].id] : []));

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Recipes', href: '/recipes' },
        { title: 'Runs', href: '/recipes/runs' },
        { title: run.recipe_name, href: `/recipes/runs/${run.id}` },
    ];

    const load = useCallback(async () => {
        const response = await fetch(route('recipes.runs.status', run.id), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (response.ok) {
            const body = (await response.json()) as { data: RunSummary & { targets: RunTargetRow[] } };
            setStatus(body.data.status);
            setTargets(body.data.targets);
        }
    }, [run.id]);

    const live = useEchoChannel<LivePayload>(`recipes.runs.${run.id}`, ['recipe.run.updated'], (_event, payload) => {
        setStatus(payload.status);
        setTargets((current) => current.map((target) => ({ ...target, ...(payload.targets.find((t) => t.id === target.id) ?? {}) })));

        if (FINISHED.includes(payload.status)) {
            void load();
        }
    });

    const finished = FINISHED.includes(status);

    useEffect(() => {
        if (live || finished) {
            return;
        }

        const timer = window.setInterval(() => void load(), 3000);

        return () => window.clearInterval(timer);
    }, [live, finished, load]);

    const toggle = (id: string) =>
        setExpanded((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    const rerunHref = run.recipe_id ? route('recipes.run', run.recipe_id) : run.builtin ? route('recipes.builtin.run', run.builtin) : null;
    const counts = {
        succeeded: targets.filter((t) => t.status === 'succeeded').length,
        failed: targets.filter((t) => t.status === 'failed' || t.status === 'unavailable').length,
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${run.recipe_name} run`} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <Heading
                            title={run.recipe_name}
                            description={`Started ${formatDistanceToNow(new Date(run.created_at), { addSuffix: true })} · runs as ${run.user} · timeout ${run.timeout_s}s`}
                        />
                    </div>
                    <div className="flex items-center gap-3">
                        <span className="text-muted-foreground text-sm tabular-nums">
                            {counts.succeeded} ok · {counts.failed} failed · {targets.length} total
                        </span>
                        <RunStatusBadge status={status} />
                        {can.run && rerunHref && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={rerunHref}>
                                    <RotateCcw /> Run again
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                <Card className="py-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-8" />
                                <TableHead>Server</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Exit code</TableHead>
                                <TableHead>Duration</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {targets.map((target) => {
                                const open = expanded.has(target.id);

                                return (
                                    <Fragment key={target.id}>
                                        <TableRow className="cursor-pointer" onClick={() => toggle(target.id)}>
                                            <TableCell>{open ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}</TableCell>
                                            <TableCell className="font-medium">{target.server_name}</TableCell>
                                            <TableCell>
                                                <RunStatusBadge status={target.status} />
                                            </TableCell>
                                            <TableCell className="font-mono text-xs">{target.exit_code ?? '—'}</TableCell>
                                            <TableCell className="text-sm tabular-nums">{formatDuration(target.duration_ms)}</TableCell>
                                        </TableRow>
                                        {open && (
                                            <TableRow>
                                                <TableCell colSpan={5} className="bg-muted/20 p-3 whitespace-normal">
                                                    {target.error && <p className="mb-2 text-sm text-red-600 dark:text-red-400">{target.error}</p>}
                                                    {target.command_id && can.viewOutput ? (
                                                        <CommandLog commandId={target.command_id} />
                                                    ) : (
                                                        !target.error && <p className="text-muted-foreground text-sm">No output available.</p>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        )}
                                    </Fragment>
                                );
                            })}
                        </TableBody>
                    </Table>
                </Card>

                <Collapsible>
                    <Card>
                        <CardHeader>
                            <CollapsibleTrigger asChild>
                                <button type="button" className="flex items-center gap-2 text-left">
                                    <ChevronRight className="size-4" />
                                    <CardTitle className="text-base">Script</CardTitle>
                                    {run.env_keys.length > 0 && (
                                        <span className="text-muted-foreground text-xs">variables: {run.env_keys.join(', ')}</span>
                                    )}
                                </button>
                            </CollapsibleTrigger>
                        </CardHeader>
                        <CollapsibleContent>
                            <CardContent>
                                <ScriptBlock script={run.script} />
                            </CardContent>
                        </CollapsibleContent>
                    </Card>
                </Collapsible>
            </div>
        </AppLayout>
    );
}
