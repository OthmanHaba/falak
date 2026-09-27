import { Button } from '@/components/kiln/button';
import { DataTable } from '@/components/kiln/data-table';
import { EmptyState } from '@/components/kiln/empty-state';
import { Input } from '@/components/kiln/input';
import { RelativeTime } from '@/components/kiln/relative-time';
import { Section } from '@/components/kiln/section';
import { Tag } from '@/components/kiln/tag';
import ServerLayout, { type ServerHeader } from '@/layouts/server-layout';
import { Link, router, usePoll } from '@inertiajs/react';
import { History, Play, ScrollText, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { formatDuration, RunStatusBadge } from '../components/run-ui';
import { type BuiltinRecipeRow, type RecipeRow, type RunSummary, type RunTargetRow } from '../types';

interface Props {
    server: ServerHeader;
    recipes: (RecipeRow & { run_url: string })[];
    builtins: (BuiltinRecipeRow & { run_url: string })[];
    runs: (RunSummary & { target: RunTargetRow | null })[];
    can: { run: boolean; manage: boolean };
}

interface Runnable {
    key: string;
    name: string;
    description: string | null;
    user: string;
    builtin: boolean;
    run_url: string;
}

const ACTIVE = new Set(['pending', 'running', 'queued']);

export default function Server({ server, recipes, builtins, runs, can }: Props) {
    const [query, setQuery] = useState('');
    const running = runs.some((run) => ACTIVE.has(run.status) || (run.target && ACTIVE.has(run.target.status)));
    usePoll(running ? 3_000 : 60_000, { only: ['runs'] });

    const runnables = useMemo<Runnable[]>(() => {
        const all: Runnable[] = [
            ...recipes.map((recipe) => ({
                key: recipe.id,
                name: recipe.name,
                description: recipe.description,
                user: recipe.user,
                builtin: false,
                run_url: recipe.run_url,
            })),
            ...builtins.map((recipe) => ({
                key: recipe.key,
                name: recipe.name,
                description: recipe.description,
                user: recipe.user,
                builtin: true,
                run_url: recipe.run_url,
            })),
        ];
        const needle = query.trim().toLowerCase();

        return needle ? all.filter((recipe) => `${recipe.name} ${recipe.description ?? ''}`.toLowerCase().includes(needle)) : all;
    }, [recipes, builtins, query]);

    return (
        <ServerLayout server={server} tab="recipes" reloadOnly={['server', 'runs']}>
            <Section
                title="Run a recipe"
                description={`Scripts run on ${server.name} through the agent, with live output.`}
                bare
                aside={
                    <div className="w-56">
                        <Input
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Filter recipes"
                            aria-label="Filter recipes"
                            prefix={<Search />}
                        />
                    </div>
                }
            >
                {runnables.length === 0 ? (
                    <EmptyState
                        size="sm"
                        icon={<ScrollText />}
                        title={query ? 'No recipes match' : 'No recipes yet'}
                        description={
                            query ? 'Try another name.' : 'Recipes are reusable scripts (e.g. clear caches, rotate logs) you can run on any server.'
                        }
                        action={
                            !query &&
                            can.manage && (
                                <Button variant="secondary" size="sm" asChild>
                                    <Link href="/settings/recipes?create=1">Create a recipe</Link>
                                </Button>
                            )
                        }
                    />
                ) : (
                    <ul className="grid gap-2 sm:grid-cols-2">
                        {runnables.map((recipe) => (
                            <li
                                key={`${recipe.builtin ? 'b' : 'r'}-${recipe.key}`}
                                className="border-border bg-surface-1 flex items-start gap-3 rounded-lg border p-3"
                            >
                                <span className="border-border bg-surface-2 text-fg-muted flex size-8 shrink-0 items-center justify-center rounded-md border">
                                    <ScrollText className="size-4" aria-hidden />
                                </span>
                                <span className="grid min-w-0 flex-1 gap-0.5">
                                    <span className="text-fg flex items-center gap-2 truncate text-sm font-medium">
                                        {recipe.name}
                                        {recipe.builtin && <Tag tone="faint">built-in</Tag>}
                                    </span>
                                    <span className="text-fg-muted line-clamp-2 text-xs">{recipe.description ?? 'No description.'}</span>
                                    <span className="text-fg-faint font-mono text-xs">as {recipe.user}</span>
                                </span>
                                {can.run && (
                                    <Button size="sm" asChild>
                                        <Link href={recipe.run_url} aria-label={`Run ${recipe.name} on ${server.name}`}>
                                            <Play aria-hidden /> Run
                                        </Link>
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            <Section
                title="History on this server"
                bare
                aside={
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={`/recipes/runs?server=${server.id}`}>All runs</Link>
                    </Button>
                }
            >
                <DataTable
                    label="Recipe runs on this server"
                    rows={runs}
                    rowKey={(run) => run.id}
                    onRowClick={(run) => router.visit(`/recipes/runs/${run.id}`)}
                    empty={{
                        icon: <History />,
                        title: 'No runs yet',
                        description: `Recipes you run on ${server.name} show up here with their output.`,
                        size: 'sm',
                    }}
                    columns={[
                        {
                            id: 'recipe',
                            header: 'Recipe',
                            cell: (run) => (
                                <Link
                                    href={`/recipes/runs/${run.id}`}
                                    className="text-fg font-medium hover:underline"
                                    onClick={(event) => event.stopPropagation()}
                                >
                                    {run.recipe_name}
                                </Link>
                            ),
                        },
                        {
                            id: 'status',
                            header: 'Result here',
                            cell: (run) => <RunStatusBadge status={run.target?.status ?? run.status} />,
                        },
                        {
                            id: 'servers',
                            header: 'Servers',
                            align: 'right',
                            hideOnMobile: true,
                            cell: (run) => <span className="text-fg-muted">{run.servers}</span>,
                        },
                        {
                            id: 'exit',
                            header: 'Exit',
                            align: 'right',
                            hideOnMobile: true,
                            cell: (run) => <span className="font-mono text-xs">{run.target?.exit_code ?? '—'}</span>,
                        },
                        {
                            id: 'duration',
                            header: 'Duration',
                            align: 'right',
                            cell: (run) => formatDuration(run.target?.duration_ms ?? null),
                        },
                        {
                            id: 'when',
                            header: 'Started',
                            align: 'right',
                            cell: (run) => <RelativeTime value={run.created_at} className="text-fg-muted text-xs" />,
                        },
                    ]}
                />
            </Section>
        </ServerLayout>
    );
}
