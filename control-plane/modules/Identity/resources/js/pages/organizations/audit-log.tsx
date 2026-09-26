import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import OrganizationLayout from '@/layouts/organization/layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { format } from 'date-fns';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { FormEventHandler, Fragment, useState } from 'react';

interface AuditEntry {
    id: string;
    action: string;
    actor_type: 'user' | 'token' | 'system' | string;
    actor_id: string | null;
    actor_name: string | null;
    subject_type: string | null;
    subject_id: string | null;
    context: Record<string, unknown>;
    ip_address: string | null;
    created_at: string;
}

interface Filters {
    action?: string;
    actor?: string;
    subject?: string;
    from?: string;
    to?: string;
}

interface AuditLogProps {
    entries: Paginated<AuditEntry>;
    filters: Filters;
    actions: string[];
}

const ALL = '__all__';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Audit log', href: '/organization/audit-log' }];

export default function AuditLog({ entries, filters, actions }: AuditLogProps) {
    const [draft, setDraft] = useState<Filters>(filters);
    const [expanded, setExpanded] = useState<string | null>(null);

    const apply: FormEventHandler = (e) => {
        e.preventDefault();
        const query = Object.fromEntries(Object.entries(draft).filter(([, value]) => value)) as Record<string, string>;
        router.get(route('organization.audit-log'), query, { preserveState: true, preserveScroll: true });
    };

    const clear = () => {
        setDraft({});
        router.get(route('organization.audit-log'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Audit log" />
            <OrganizationLayout wide>
                <div className="space-y-4">
                    <HeadingSmall title="Audit log" description="Security-relevant changes in this organization, newest first." />

                    <form onSubmit={apply} className="grid gap-3 md:grid-cols-5 md:items-end">
                        <div className="grid gap-1.5">
                            <Label>Action</Label>
                            <Select
                                value={draft.action ?? ALL}
                                onValueChange={(value) => setDraft({ ...draft, action: value === ALL ? undefined : value })}
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>All actions</SelectItem>
                                    {actions.map((action) => (
                                        <SelectItem key={action} value={action}>
                                            {action}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="actor">Actor id</Label>
                            <Input id="actor" value={draft.actor ?? ''} onChange={(e) => setDraft({ ...draft, actor: e.target.value })} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="from">From</Label>
                            <Input id="from" type="date" value={draft.from ?? ''} onChange={(e) => setDraft({ ...draft, from: e.target.value })} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="to">To</Label>
                            <Input id="to" type="date" value={draft.to ?? ''} onChange={(e) => setDraft({ ...draft, to: e.target.value })} />
                        </div>
                        <div className="flex gap-2">
                            <Button type="submit">Filter</Button>
                            <Button type="button" variant="ghost" onClick={clear}>
                                Reset
                            </Button>
                        </div>
                    </form>

                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-8" />
                                <TableHead>When</TableHead>
                                <TableHead>Actor</TableHead>
                                <TableHead>Action</TableHead>
                                <TableHead>Subject</TableHead>
                                <TableHead>IP</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {entries.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={6} className="text-muted-foreground py-6 text-center">
                                        No entries match these filters.
                                    </TableCell>
                                </TableRow>
                            )}
                            {entries.data.map((entry) => (
                                <Fragment key={entry.id}>
                                    <TableRow className="cursor-pointer" onClick={() => setExpanded(expanded === entry.id ? null : entry.id)}>
                                        <TableCell>
                                            {expanded === entry.id ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap">
                                            {format(new Date(entry.created_at), 'yyyy-MM-dd HH:mm:ss')}
                                        </TableCell>
                                        <TableCell>
                                            <span>{entry.actor_name ?? 'Unknown'}</span>{' '}
                                            {entry.actor_type !== 'user' && (
                                                <Badge variant="outline" className="text-[10px]">
                                                    {entry.actor_type}
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">{entry.action}</TableCell>
                                        <TableCell className="text-muted-foreground text-xs">
                                            {entry.subject_type ? `${entry.subject_type}:${entry.subject_id ?? ''}` : '—'}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground text-xs">{entry.ip_address ?? '—'}</TableCell>
                                    </TableRow>
                                    {expanded === entry.id && (
                                        <TableRow>
                                            <TableCell />
                                            <TableCell colSpan={5}>
                                                <pre className="bg-muted overflow-x-auto rounded-md p-3 text-xs">
                                                    {JSON.stringify(entry.context, null, 2)}
                                                </pre>
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </Fragment>
                            ))}
                        </TableBody>
                    </Table>

                    {entries.last_page > 1 && (
                        <nav className="flex flex-wrap items-center gap-1" aria-label="Pagination">
                            {entries.links.map((link, index) =>
                                link.url ? (
                                    <Button key={index} asChild size="sm" variant={link.active ? 'default' : 'ghost'}>
                                        <Link href={link.url} preserveScroll preserveState>
                                            <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                        </Link>
                                    </Button>
                                ) : (
                                    <Button key={index} size="sm" variant="ghost" disabled>
                                        <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                    </Button>
                                ),
                            )}
                        </nav>
                    )}
                </div>
            </OrganizationLayout>
        </AppLayout>
    );
}
