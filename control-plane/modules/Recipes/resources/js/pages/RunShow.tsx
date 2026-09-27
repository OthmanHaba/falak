import { CommandLog } from '@/components/command-log';
import { AppShell } from '@/components/kiln/app-shell';
import { Button } from '@/components/kiln/button';
import { RelativeTime } from '@/components/kiln/relative-time';
import { PageHeader, Section } from '@/components/kiln/section';
import { Tag } from '@/components/kiln/tag';
import { useEchoChannel } from '@/hooks/use-echo-channel';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ChevronDown, ChevronRight, RotateCcw } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
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
    const [expanded, setExpanded] = useState<Set<string>>(
        () => new Set(initialTargets.length === 1 ? [initialTargets[0].id] : initialTargets.filter((t) => t.status === 'failed').map((t) => t.id)),
    );
    const [showScript, setShowScript] = useState(false);

    const single = initialTargets.length === 1 ? initialTargets[0] : null;
    const breadcrumbs: BreadcrumbItem[] = single
        ? [
              { title: 'Infrastructure', href: '/servers' },
              { title: single.server_name, href: `/servers/${single.server_id}` },
              { title: 'Recipes', href: `/servers/${single.server_id}/recipes` },
              { title: run.recipe_name, href: `/recipes/runs/${run.id}` },
          ]
        : [
              { title: 'Infrastructure', href: '/servers' },
              { title: 'Recipe runs', href: '/recipes/runs' },
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
            if (next.has(id)) next.delete(id);
            else next.add(id);

            return next;
        });

    const rerunHref = run.recipe_id ? route('recipes.run', run.recipe_id) : run.builtin ? route('recipes.builtin.run', run.builtin) : null;
    const rerunWithServers = rerunHref ? `${rerunHref}?server=${targets.map((t) => t.server_id).join(',')}` : null;
    const counts = {
        succeeded: targets.filter((t) => t.status === 'succeeded').length,
        failed: targets.filter((t) => t.status === 'failed' || t.status === 'unavailable').length,
        running: targets.filter((t) => t.status === 'running' || t.status === 'queued').length,
    };

    return (
        <AppShell breadcrumbs={breadcrumbs}>
            <Head title={`${run.recipe_name} run`} />
            <div className="grid gap-6">
                <PageHeader
                    title={
                        <span className="flex items-center gap-2.5">
                            {run.recipe_name}
                            <RunStatusBadge status={status} />
                        </span>
                    }
                    description={
                        <>
                            Started <RelativeTime value={run.created_at} /> · runs as <span className="font-mono">{run.user}</span> · timeout{' '}
                            {run.timeout_s}s
                        </>
                    }
                    actions={
                        can.run &&
                        rerunWithServers && (
                            <Button asChild>
                                <Link href={rerunWithServers}>
                                    <RotateCcw aria-hidden /> Run again
                                </Link>
                            </Button>
                        )
                    }
                />

                <div className="flex flex-wrap items-center gap-2 text-sm" aria-live="polite">
                    <Tag tone="success">
                        <span className="tabular">{counts.succeeded}</span> succeeded
                    </Tag>
                    {counts.failed > 0 && (
                        <Tag tone="danger">
                            <span className="tabular">{counts.failed}</span> failed
                        </Tag>
                    )}
                    {counts.running > 0 && (
                        <Tag tone="warning">
                            <span className="tabular">{counts.running}</span> in progress
                        </Tag>
                    )}
                    <span className="text-fg-faint text-xs">
                        of {targets.length} server{targets.length === 1 ? '' : 's'}
                    </span>
                </div>

                <ul className="border-border bg-surface-1 divide-border divide-y overflow-hidden rounded-lg border">
                    {targets.map((target) => {
                        const open = expanded.has(target.id);

                        return (
                            <li key={target.id}>
                                <button
                                    type="button"
                                    onClick={() => toggle(target.id)}
                                    aria-expanded={open}
                                    className="hover:bg-surface-2 flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors duration-150"
                                >
                                    {open ? (
                                        <ChevronDown className="text-fg-faint size-4 shrink-0" aria-hidden />
                                    ) : (
                                        <ChevronRight className="text-fg-faint size-4 shrink-0" aria-hidden />
                                    )}
                                    <span className="text-fg min-w-0 flex-1 truncate text-sm font-medium">{target.server_name}</span>
                                    <RunStatusBadge status={target.status} />
                                    <span className="text-fg-faint hidden w-16 text-right font-mono text-xs sm:inline">
                                        {target.exit_code !== null ? `exit ${target.exit_code}` : ''}
                                    </span>
                                    <span className="text-fg-muted tabular w-16 text-right text-xs">{formatDuration(target.duration_ms)}</span>
                                </button>
                                {open && (
                                    <div className={cn('grid gap-2 px-3 pb-3')}>
                                        {target.error && <p className="text-danger text-sm">{target.error}</p>}
                                        {target.command_id && can.viewOutput ? (
                                            <CommandLog commandId={target.command_id} />
                                        ) : (
                                            !target.error && (
                                                <p className="text-fg-muted text-sm">
                                                    {can.viewOutput ? 'No output yet.' : 'You need the fleet.commands.view permission to see output.'}
                                                </p>
                                            )
                                        )}
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>

                <Section
                    title="Script"
                    description={run.env_keys.length > 0 ? `Variables: ${run.env_keys.join(', ')}` : undefined}
                    bare
                    aside={
                        <Button variant="ghost" size="sm" onClick={() => setShowScript((value) => !value)} aria-expanded={showScript}>
                            {showScript ? 'Hide' : 'Show'}
                        </Button>
                    }
                >
                    {showScript && <ScriptBlock script={run.script} title={`${run.user} · bash`} />}
                </Section>
            </div>
        </AppShell>
    );
}
