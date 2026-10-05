import { Button } from '@/components/falak/button';
import { CodeBlock } from '@/components/falak/code-block';
import { EmptyState } from '@/components/falak/empty-state';
import { Field } from '@/components/falak/field';
import { Input } from '@/components/falak/input';
import { Select } from '@/components/falak/select';
import { Tag } from '@/components/falak/tag';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import { type Paginated } from '@/types';
import { Link, router } from '@inertiajs/react';
import { format } from 'date-fns';
import { ChevronDown, ChevronRight, ScrollText } from 'lucide-react';
import { Fragment, useState, type FormEventHandler } from 'react';

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

export default function AuditLog({ entries, filters, actions }: AuditLogProps) {
    const [draft, setDraft] = useState<Filters>(filters);
    const [expanded, setExpanded] = useState<string | null>(null);

    const apply: FormEventHandler = (event) => {
        event.preventDefault();
        const query = Object.fromEntries(Object.entries(draft).filter(([, value]) => value)) as Record<string, string>;
        router.get(route('organization.audit-log'), query, { preserveState: true, preserveScroll: true });
    };

    const clear = () => {
        setDraft({});
        router.get(route('organization.audit-log'));
    };

    return (
        <SettingsLayout title="Audit log" description="Security-relevant changes in this organization, newest first." wide>
            <form
                onSubmit={apply}
                className="border-border bg-surface-1 grid gap-3 rounded-lg border p-3 sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1fr_auto] lg:items-end"
            >
                <Field label="Action">
                    <Select
                        value={draft.action ?? ALL}
                        onValueChange={(value) => setDraft({ ...draft, action: value === ALL ? undefined : value })}
                        options={[{ value: ALL, label: 'All actions' }, ...actions.map((action) => ({ value: action, label: action }))]}
                    />
                </Field>
                <Field label="Actor id">
                    <Input value={draft.actor ?? ''} onChange={(event) => setDraft({ ...draft, actor: event.target.value })} mono />
                </Field>
                <Field label="From">
                    <Input type="date" value={draft.from ?? ''} onChange={(event) => setDraft({ ...draft, from: event.target.value })} />
                </Field>
                <Field label="To">
                    <Input type="date" value={draft.to ?? ''} onChange={(event) => setDraft({ ...draft, to: event.target.value })} />
                </Field>
                <div className="flex gap-2">
                    <Button type="submit">Filter</Button>
                    <Button variant="ghost" onClick={clear}>
                        Reset
                    </Button>
                </div>
            </form>

            {entries.data.length === 0 ? (
                <EmptyState
                    icon={<ScrollText />}
                    title="No entries"
                    description="Nothing matches these filters. Sign-ins, role changes, deploys and deletions show up here."
                />
            ) : (
                <div className="border-border bg-surface-1 overflow-x-auto rounded-lg border">
                    <table className="w-full text-left text-xs">
                        <caption className="sr-only">Audit log entries</caption>
                        <thead className="border-border text-fg-faint border-b">
                            <tr>
                                <th className="w-8" />
                                <th className="h-8 px-3 font-medium">When</th>
                                <th className="px-3 font-medium">Actor</th>
                                <th className="px-3 font-medium">Action</th>
                                <th className="hidden px-3 font-medium md:table-cell">Subject</th>
                                <th className="hidden px-3 font-medium md:table-cell">IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            {entries.data.map((entry) => {
                                const open = expanded === entry.id;

                                return (
                                    <Fragment key={entry.id}>
                                        <tr className="border-border hover:bg-surface-2 border-b text-sm last:border-0">
                                            <td className="pl-2">
                                                <button
                                                    type="button"
                                                    className="text-fg-faint hover:text-fg rounded-sm p-1"
                                                    aria-expanded={open}
                                                    aria-label={open ? 'Hide details' : 'Show details'}
                                                    onClick={() => setExpanded(open ? null : entry.id)}
                                                >
                                                    {open ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                                                </button>
                                            </td>
                                            <td className="text-fg-muted tabular h-10 px-3 whitespace-nowrap">
                                                {format(new Date(entry.created_at), 'yyyy-MM-dd HH:mm:ss')}
                                            </td>
                                            <td className="px-3">
                                                <span className="flex items-center gap-1.5">
                                                    {entry.actor_name ?? 'Unknown'}
                                                    {entry.actor_type !== 'user' && <Tag>{entry.actor_type}</Tag>}
                                                </span>
                                            </td>
                                            <td className="px-3 font-mono text-xs">{entry.action}</td>
                                            <td className="text-2xs text-fg-faint hidden px-3 font-mono md:table-cell">
                                                {entry.subject_type ? `${entry.subject_type}:${entry.subject_id ?? ''}` : '—'}
                                            </td>
                                            <td className="text-2xs text-fg-faint hidden px-3 font-mono md:table-cell">{entry.ip_address ?? '—'}</td>
                                        </tr>
                                        {open && (
                                            <tr className="border-border border-b">
                                                <td />
                                                <td colSpan={5} className="px-3 py-2">
                                                    <CodeBlock code={JSON.stringify(entry.context, null, 2)} maxHeight={320} />
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}

            {entries.last_page > 1 && (
                <nav className="flex flex-wrap items-center gap-1" aria-label="Pagination">
                    {entries.links.map((link, index) => {
                        const label = <span dangerouslySetInnerHTML={{ __html: link.label }} />;
                        const classes = cn(
                            'inline-flex h-7 min-w-7 items-center justify-center rounded-md px-2 text-xs',
                            link.active ? 'bg-surface-3 text-fg font-medium' : 'text-fg-muted hover:bg-surface-2 hover:text-fg',
                            !link.url && 'pointer-events-none opacity-40',
                        );

                        return link.url ? (
                            <Link
                                key={index}
                                href={link.url}
                                preserveScroll
                                preserveState
                                className={classes}
                                aria-current={link.active ? 'page' : undefined}
                            >
                                {label}
                            </Link>
                        ) : (
                            <span key={index} className={classes}>
                                {label}
                            </span>
                        );
                    })}
                </nav>
            )}
        </SettingsLayout>
    );
}
