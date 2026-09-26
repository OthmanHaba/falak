import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { History as HistoryIcon } from 'lucide-react';
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
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Recipes', href: '/recipes' },
    { title: 'Runs', href: '/recipes/runs' },
];

function duration(run: RunSummary): number | null {
    return run.started_at && run.finished_at ? new Date(run.finished_at).getTime() - new Date(run.started_at).getTime() : null;
}

export default function History({ runs, filters, recipes, servers }: Props) {
    const apply = (next: Partial<Props['filters']> & { page?: number }) => {
        const query = { ...filters, ...next };
        const cleaned = Object.fromEntries(Object.entries(query).filter(([, value]) => value && value !== ALL));
        router.get(route('recipes.runs.index'), cleaned, { preserveState: true, replace: true });
    };

    const filterSelect = (key: keyof Props['filters'], placeholder: string, options: Option[]) => (
        <Select value={filters[key] ?? ALL} onValueChange={(value) => apply({ [key]: value, page: undefined })}>
            <SelectTrigger className="w-48" aria-label={placeholder}>
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>{placeholder}</SelectItem>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Recipe runs" />
            <div className="space-y-6 p-4">
                <Heading title="Run history" description={`${runs.total} recipe run${runs.total === 1 ? '' : 's'}`} />

                <div className="flex flex-wrap gap-2">
                    {filterSelect('recipe', 'All recipes', recipes)}
                    {filterSelect('server', 'All servers', servers)}
                    {filterSelect(
                        'status',
                        'All statuses',
                        STATUSES.map((status) => ({ value: status, label: status[0].toUpperCase() + status.slice(1) })),
                    )}
                </div>

                {runs.data.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-2 py-12 text-center">
                            <HistoryIcon className="text-muted-foreground size-10" />
                            <p className="font-medium">No runs found</p>
                        </CardContent>
                    </Card>
                ) : (
                    <Card className="py-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Recipe</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Servers</TableHead>
                                    <TableHead>Duration</TableHead>
                                    <TableHead>Started</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {runs.data.map((run) => (
                                    <TableRow key={run.id}>
                                        <TableCell>
                                            <Link href={route('recipes.runs.show', run.id)} className="font-medium hover:underline">
                                                {run.recipe_name}
                                            </Link>
                                            {run.builtin && <span className="text-muted-foreground ml-2 text-xs">built-in</span>}
                                        </TableCell>
                                        <TableCell>
                                            <RunStatusBadge status={run.status} />
                                        </TableCell>
                                        <TableCell className="text-sm tabular-nums">
                                            {run.succeeded}/{run.servers} ok
                                            {run.failed > 0 && <span className="text-red-600 dark:text-red-400"> · {run.failed} failed</span>}
                                        </TableCell>
                                        <TableCell className="text-sm tabular-nums">{formatDuration(duration(run))}</TableCell>
                                        <TableCell className="text-muted-foreground text-sm">
                                            {formatDistanceToNow(new Date(run.created_at), { addSuffix: true })}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                )}

                {runs.last_page > 1 && (
                    <div className="flex items-center justify-end gap-2 text-sm">
                        <Button variant="outline" size="sm" disabled={runs.current_page <= 1} onClick={() => apply({ page: runs.current_page - 1 })}>
                            Previous
                        </Button>
                        <span className="text-muted-foreground">
                            Page {runs.current_page} of {runs.last_page}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={runs.current_page >= runs.last_page}
                            onClick={() => apply({ page: runs.current_page + 1 })}
                        >
                            Next
                        </Button>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
