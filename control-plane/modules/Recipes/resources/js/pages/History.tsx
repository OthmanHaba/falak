import { Button } from '@/components/kiln/button';
import { DataTable } from '@/components/kiln/data-table';
import { RelativeTime } from '@/components/kiln/relative-time';
import { Select } from '@/components/kiln/select';
import { Tag } from '@/components/kiln/tag';
import InfrastructureLayout from '@/layouts/infrastructure-layout';
import { Link, router, usePoll } from '@inertiajs/react';
import { History as HistoryIcon, ScrollText, X } from 'lucide-react';
import { formatDuration, RunStatusBadge } from '../components/run-ui';
import { type Option, type RunStatus, type RunSummary } from '../types';

interface Props {
    runs: { data: RunSummary[]; current_page: number; last_page: number; total: number };
    filters: { status?: string; recipe?: string; server?: string };
    recipes: Option[];
    servers: Option[];
}

const ALL = 'all';
const STATUSES: RunStatus[] = ['pending', 'running', 'succeeded', 'partial', 'failed'];

function duration(run: RunSummary): number | null {
    return run.started_at && run.finished_at ? new Date(run.finished_at).getTime() - new Date(run.started_at).getTime() : null;
}

export default function History({ runs, filters, recipes, servers }: Props) {
    const active = runs.data.some((run) => run.status === 'pending' || run.status === 'running');
    usePoll(active ? 3_000 : 60_000, { only: ['runs'] });

    const apply = (next: Partial<Props['filters']> & { page?: number }) => {
        const query = { ...filters, ...next };
        const cleaned = Object.fromEntries(Object.entries(query).filter(([, value]) => value && value !== ALL));
        router.get(route('recipes.runs.index'), cleaned, { preserveState: true, preserveScroll: true, replace: true });
    };

    const filterSelect = (key: keyof Props['filters'], placeholder: string, options: Option[]) => (
        <Select
            value={filters[key] ?? ALL}
            onValueChange={(value) => apply({ [key]: value, page: undefined })}
            options={[{ value: ALL, label: placeholder }, ...options]}
            aria-label={placeholder}
            className="w-44"
        />
    );

    const filtered = Boolean(filters.status || filters.recipe || filters.server);

    return (
        <InfrastructureLayout
            section="runs"
            title="Recipe runs"
            description="Every recipe run across your servers, with per-server output."
            actions={
                <Button asChild>
                    <Link href="/settings/recipes">
                        <ScrollText aria-hidden /> Recipes
                    </Link>
                </Button>
            }
        >
            <div className="flex flex-wrap items-center gap-2">
                {filterSelect('recipe', 'All recipes', recipes)}
                {filterSelect('server', 'All servers', servers)}
                {filterSelect(
                    'status',
                    'All statuses',
                    STATUSES.map((status) => ({ value: status, label: status[0].toUpperCase() + status.slice(1) })),
                )}
                {filtered && (
                    <Button
                        variant="ghost"
                        size="sm"
                        icon={<X />}
                        onClick={() => router.get(route('recipes.runs.index'), {}, { preserveState: true, replace: true })}
                    >
                        Clear
                    </Button>
                )}
                <span className="text-fg-faint tabular ml-auto text-xs">
                    {runs.total} run{runs.total === 1 ? '' : 's'}
                </span>
            </div>

            <DataTable
                label="Recipe runs"
                rows={runs.data}
                rowKey={(run) => run.id}
                onRowClick={(run) => router.visit(route('recipes.runs.show', run.id))}
                empty={{
                    icon: <HistoryIcon />,
                    title: filtered ? 'No runs match these filters' : 'No recipe runs yet',
                    description: filtered
                        ? 'Try another recipe, server or status.'
                        : 'Run a recipe from a server’s Recipes tab and its output shows up here.',
                }}
                columns={[
                    {
                        id: 'recipe',
                        header: 'Recipe',
                        cell: (run) => (
                            <span className="flex items-center gap-2">
                                <Link
                                    href={route('recipes.runs.show', run.id)}
                                    className="text-fg font-medium hover:underline"
                                    onClick={(event) => event.stopPropagation()}
                                >
                                    {run.recipe_name}
                                </Link>
                                {run.builtin && <Tag tone="faint">built-in</Tag>}
                            </span>
                        ),
                    },
                    { id: 'status', header: 'Status', cell: (run) => <RunStatusBadge status={run.status} /> },
                    {
                        id: 'servers',
                        header: 'Servers',
                        cell: (run) => (
                            <span className="tabular text-xs">
                                <span className="text-fg">
                                    {run.succeeded}/{run.servers}
                                </span>{' '}
                                <span className="text-fg-muted">ok</span>
                                {run.failed > 0 && <span className="text-danger"> · {run.failed} failed</span>}
                            </span>
                        ),
                    },
                    { id: 'duration', header: 'Duration', align: 'right', hideOnMobile: true, cell: (run) => formatDuration(duration(run)) },
                    {
                        id: 'started',
                        header: 'Started',
                        align: 'right',
                        cell: (run) => <RelativeTime value={run.created_at} className="text-fg-muted text-xs" />,
                    },
                ]}
            />

            {runs.last_page > 1 && (
                <div className="flex items-center justify-end gap-2 text-sm">
                    <Button size="sm" disabled={runs.current_page <= 1} onClick={() => apply({ page: runs.current_page - 1 })}>
                        Previous
                    </Button>
                    <span className="text-fg-muted tabular text-xs">
                        Page {runs.current_page} of {runs.last_page}
                    </span>
                    <Button size="sm" disabled={runs.current_page >= runs.last_page} onClick={() => apply({ page: runs.current_page + 1 })}>
                        Next
                    </Button>
                </div>
            )}
        </InfrastructureLayout>
    );
}
