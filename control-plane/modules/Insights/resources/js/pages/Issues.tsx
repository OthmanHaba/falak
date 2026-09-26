import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import { ago, formatCount, IssueKindBadge, IssueStatusBadge, PriorityBadge } from '../components/insights-ui';
import { type IssueSummary, type Member } from '../types';

interface Filters {
    status: string;
    kind?: string;
    priority?: string;
    site?: string;
    assignee?: string;
    search?: string;
    sort?: string;
}

interface Props {
    issues: Paginated<IssueSummary>;
    filters: Filters;
    members: Member[];
    sites: Record<string, string>;
}

const ALL = 'all';
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Insights', href: '/insights' },
    { title: 'Issues', href: '/insights/issues' },
];

function FilterSelect({
    value,
    onChange,
    label,
    options,
}: {
    value: string;
    onChange: (v: string) => void;
    label: string;
    options: { value: string; label: string }[];
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger className="w-40" aria-label={label}>
                <SelectValue placeholder={label} />
            </SelectTrigger>
            <SelectContent>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

export default function Issues({ issues, filters, members, sites }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');

    const apply = (next: Partial<Filters>) => {
        const query = { ...filters, search, ...next };
        const cleaned = Object.fromEntries(Object.entries(query).filter(([key, value]) => value && (value !== ALL || key === 'status')));
        router.get(route('insights.issues.index'), cleaned, { preserveState: true, replace: true });
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        apply({ search });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Issues" />
            <div className="space-y-6 p-4">
                <Heading title="Issues" description="Exceptions, slow endpoints and scheduled tasks that need attention" />

                <div className="flex flex-wrap items-center gap-2">
                    <form onSubmit={submit} className="flex-1 sm:max-w-xs">
                        <Input
                            placeholder="Search title or culprit…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            aria-label="Search issues"
                        />
                    </form>
                    <FilterSelect
                        label="Status"
                        value={filters.status}
                        onChange={(status) => apply({ status })}
                        options={[
                            { value: 'open', label: 'Open' },
                            { value: 'resolved', label: 'Resolved' },
                            { value: 'ignored', label: 'Ignored' },
                            { value: ALL, label: 'Any status' },
                        ]}
                    />
                    <FilterSelect
                        label="Kind"
                        value={filters.kind ?? ALL}
                        onChange={(kind) => apply({ kind })}
                        options={[
                            { value: ALL, label: 'All kinds' },
                            { value: 'exception', label: 'Exceptions' },
                            { value: 'performance', label: 'Performance' },
                            { value: 'heartbeat', label: 'Scheduled tasks' },
                        ]}
                    />
                    <FilterSelect
                        label="Site"
                        value={filters.site ?? ALL}
                        onChange={(site) => apply({ site })}
                        options={[{ value: ALL, label: 'All sites' }, ...Object.entries(sites).map(([id, name]) => ({ value: id, label: name }))]}
                    />
                    <FilterSelect
                        label="Assignee"
                        value={filters.assignee ?? ALL}
                        onChange={(assignee) => apply({ assignee })}
                        options={[
                            { value: ALL, label: 'Anyone' },
                            { value: 'me', label: 'Assigned to me' },
                            { value: 'none', label: 'Unassigned' },
                            ...members.map((member) => ({ value: member.id, label: member.name })),
                        ]}
                    />
                    <FilterSelect
                        label="Sort"
                        value={filters.sort ?? 'last_seen'}
                        onChange={(sort) => apply({ sort })}
                        options={[
                            { value: 'last_seen', label: 'Last seen' },
                            { value: 'first_seen', label: 'First seen' },
                            { value: 'occurrences', label: 'Occurrences' },
                            { value: 'users', label: 'Users affected' },
                        ]}
                    />
                </div>

                <Card className="py-0">
                    {issues.data.length === 0 ? (
                        <p className="text-muted-foreground py-12 text-center text-sm">No issues match these filters.</p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Issue</TableHead>
                                    <TableHead>Site</TableHead>
                                    <TableHead className="text-right">Events</TableHead>
                                    <TableHead className="text-right">Users</TableHead>
                                    <TableHead>Last seen</TableHead>
                                    <TableHead>Assignee</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {issues.data.map((issue) => (
                                    <TableRow key={issue.id}>
                                        <TableCell className="max-w-[36rem]">
                                            <Link
                                                href={route('insights.issues.show', issue.id)}
                                                className="block truncate font-medium hover:underline"
                                            >
                                                {issue.title}
                                            </Link>
                                            <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                                <IssueStatusBadge status={issue.status} />
                                                <IssueKindBadge kind={issue.kind} />
                                                <PriorityBadge priority={issue.priority} />
                                                {issue.handled === false && <span className="text-xs text-red-600 dark:text-red-400">unhandled</span>}
                                                {issue.culprit && <span className="text-muted-foreground truncate text-xs">{issue.culprit}</span>}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-sm">{issue.site_name ?? '—'}</TableCell>
                                        <TableCell className="text-right tabular-nums">{formatCount(issue.occurrences)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{formatCount(issue.affected_users)}</TableCell>
                                        <TableCell className="text-muted-foreground text-sm whitespace-nowrap" title={issue.last_seen_at}>
                                            {ago(issue.last_seen_at)}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {issue.assignee?.name ?? <span className="text-muted-foreground">—</span>}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Card>

                {issues.last_page > 1 && (
                    <nav className="flex flex-wrap items-center justify-center gap-1" aria-label="Pagination">
                        {issues.links.map((link, index) => (
                            <Button
                                key={index}
                                size="sm"
                                variant={link.active ? 'secondary' : 'ghost'}
                                disabled={!link.url}
                                asChild={Boolean(link.url)}
                            >
                                {link.url ? (
                                    <Link href={link.url} preserveState dangerouslySetInnerHTML={{ __html: link.label }} />
                                ) : (
                                    <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                )}
                            </Button>
                        ))}
                    </nav>
                )}
            </div>
        </AppLayout>
    );
}
