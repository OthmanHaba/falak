import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { format } from 'date-fns';
import { Activity, CheckCircle2, CircleDot, ExternalLink, EyeOff, ScrollText, Trash2 } from 'lucide-react';
import { FormEventHandler } from 'react';
import { Bar, BarChart, CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { TraceTimelineCard } from '../../../../Telemetry/resources/js/components/trace-waterfall';
import { ago, formatCount, formatMs, IssueKindBadge, IssueStatusBadge, PriorityBadge, SERIES, StackTraceView } from '../components/insights-ui';
import { type Frame, type IssuePriority, type IssueStatus, type IssueSummary, type Member } from '../types';

interface IssueDetail extends IssueSummary {
    exception_type: string | null;
    event_type: string | null;
    unhandled_occurrences: number;
    regressions: number;
    regressed_at: string | null;
    resolved_at: string | null;
    resolved_by: string | null;
    server_id: string | null;
    sample: {
        message?: string;
        stacktrace?: string | null;
        route_or_name?: string | null;
        handled?: boolean;
        span_id?: string | null;
        at?: string;
    } | null;
    meta: Record<string, string | number | null> | null;
    frames: Frame[];
    trace_id: string | null;
}

interface Props {
    issue: IssueDetail;
    occurrences: {
        id: number;
        at: string;
        message: string;
        handled: boolean;
        route_or_name: string | null;
        server_id: string | null;
        trace_id: string | null;
        trace_url: string | null;
    }[];
    timeline: { t: string; value: number | null; secondary?: number | null }[];
    monitor: {
        id: string;
        job: string;
        schedule: string | null;
        timezone: string;
        last_status: string | null;
        last_run_at: string | null;
        next_expected_at: string | null;
        runs: { status: string; exit_code: number | null; duration_ms: number | null; scheduled_at: string; at: string }[];
    } | null;
    activity: { id: string; type: string; user: string | null; data: Record<string, unknown> | null; at: string }[];
    comments: { id: string; body: string; user: string; at: string; can_delete: boolean }[];
    members: Member[];
    links: { trace: string | null; traceLogs: string | null; grafanaTrace: string | null; siteLogs: string | null };
    priorities: IssuePriority[];
    can: { manage: boolean };
}

const UNASSIGNED = 'none';

function describeActivity(entry: Props['activity'][number], members: Member[]): string {
    const who = entry.user ?? 'Kiln';

    switch (entry.type) {
        case 'opened':
            return 'Issue first seen';
        case 'regressed':
            return 'Regressed: occurred again after being resolved';
        case 'resolved':
            return entry.data?.automatic ? 'Resolved automatically (recovered)' : `${who} resolved the issue`;
        case 'ignored':
            return `${who} ignored the issue`;
        case 'reopened':
            return `${who} reopened the issue`;
        case 'assigned':
            return `${who} assigned ${members.find((m) => m.id === entry.data?.assignee_id)?.name ?? 'a former member'}`;
        case 'unassigned':
            return `${who} removed the assignee`;
        case 'priority':
            return `${who} changed priority to ${String(entry.data?.to ?? '')}`;
        case 'commented':
            return `${who} commented`;
        case 'comment_deleted':
            return `${who} deleted a comment`;
        default:
            return entry.type;
    }
}

export default function Issue({ issue, occurrences, timeline, monitor, activity, comments, members, links, priorities, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Insights', href: '/insights' },
        { title: 'Issues', href: '/insights/issues' },
        { title: issue.title.length > 40 ? `${issue.title.slice(0, 40)}…` : issue.title, href: route('insights.issues.show', issue.id) },
    ];
    const comment = useForm({ body: '' });
    const options = { preserveScroll: true };

    const setStatus = (status: IssueStatus) => router.put(route('insights.issues.status', issue.id), { status }, options);
    const setAssignee = (value: string) =>
        router.put(route('insights.issues.assign', issue.id), { assignee_id: value === UNASSIGNED ? null : value }, options);
    const setPriority = (priority: string) => router.put(route('insights.issues.priority', issue.id), { priority }, options);

    const submitComment: FormEventHandler = (event) => {
        event.preventDefault();
        comment.post(route('insights.issues.comments.store', issue.id), { ...options, onSuccess: () => comment.reset() });
    };

    const performance = issue.kind === 'performance';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={issue.title} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-2">
                        <div className="flex flex-wrap items-center gap-1.5">
                            <IssueStatusBadge status={issue.status} />
                            <IssueKindBadge kind={issue.kind} />
                            <PriorityBadge priority={issue.priority} />
                            {issue.regressions > 0 && (
                                <span className="text-xs text-orange-600 dark:text-orange-400">regressed {issue.regressions}×</span>
                            )}
                        </div>
                        <h1 className="text-xl font-semibold break-words">{issue.title}</h1>
                        {issue.culprit && <p className="text-muted-foreground font-mono text-sm break-all">{issue.culprit}</p>}
                        <p className="text-muted-foreground text-sm">
                            {issue.site_id ? (
                                <Link href={route('insights.sites.show', issue.site_id)} className="hover:underline">
                                    {issue.site_name}
                                </Link>
                            ) : (
                                'Server-level task'
                            )}{' '}
                            · first seen {ago(issue.first_seen_at)} · last seen {ago(issue.last_seen_at)}
                            {issue.resolved_at && ` · resolved ${ago(issue.resolved_at)}${issue.resolved_by ? ` by ${issue.resolved_by}` : ''}`}
                        </p>
                    </div>
                    {can.manage && (
                        <div className="flex flex-wrap gap-2">
                            {issue.status !== 'resolved' && (
                                <Button onClick={() => setStatus('resolved')}>
                                    <CheckCircle2 /> Resolve
                                </Button>
                            )}
                            {issue.status !== 'ignored' && (
                                <Button variant="outline" onClick={() => setStatus('ignored')}>
                                    <EyeOff /> Ignore
                                </Button>
                            )}
                            {issue.status !== 'open' && (
                                <Button variant="outline" onClick={() => setStatus('open')}>
                                    <CircleDot /> Reopen
                                </Button>
                            )}
                        </div>
                    )}
                </div>

                <div className="grid gap-4 lg:grid-cols-[1fr_20rem]">
                    <div className="min-w-0 space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-sm">
                                    {performance ? 'p95 over the last 24 hours' : 'Occurrences over the last 24 hours'}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="h-44 w-full">
                                    <ResponsiveContainer width="100%" height="100%">
                                        {performance ? (
                                            <LineChart data={timeline} margin={{ top: 5, right: 8, bottom: 0, left: -8 }}>
                                                <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-border" />
                                                <XAxis
                                                    dataKey="t"
                                                    tickFormatter={(v: string) => format(new Date(v), 'HH:mm')}
                                                    fontSize={11}
                                                    tickLine={false}
                                                    axisLine={false}
                                                    minTickGap={40}
                                                />
                                                <YAxis tickFormatter={(v: number) => formatMs(v)} fontSize={11} tickLine={false} axisLine={false} />
                                                <Tooltip
                                                    labelFormatter={(v) => format(new Date(String(v)), 'PPp')}
                                                    formatter={(v) => formatMs(Number(v))}
                                                />
                                                <Line dataKey="value" name="p95" stroke={SERIES.primary} strokeWidth={2} dot={false} connectNulls />
                                            </LineChart>
                                        ) : (
                                            <BarChart data={timeline} margin={{ top: 5, right: 8, bottom: 0, left: -16 }}>
                                                <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-border" />
                                                <XAxis
                                                    dataKey="t"
                                                    tickFormatter={(v: string) => format(new Date(v), 'HH:mm')}
                                                    fontSize={11}
                                                    tickLine={false}
                                                    axisLine={false}
                                                    minTickGap={40}
                                                />
                                                <YAxis allowDecimals={false} fontSize={11} tickLine={false} axisLine={false} />
                                                <Tooltip labelFormatter={(v) => format(new Date(String(v)), 'PPp')} cursor={{ fillOpacity: 0.1 }} />
                                                <Bar dataKey="value" name="Occurrences" fill={SERIES.primary} radius={[4, 4, 0, 0]} />
                                            </BarChart>
                                        )}
                                    </ResponsiveContainer>
                                </div>
                            </CardContent>
                        </Card>

                        {issue.kind === 'exception' && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-sm">Stack trace</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    {issue.sample?.message && (
                                        <p className="font-mono text-sm break-words whitespace-pre-wrap">{issue.sample.message}</p>
                                    )}
                                    <StackTraceView frames={issue.frames} raw={issue.sample?.stacktrace ?? null} />
                                </CardContent>
                            </Card>
                        )}

                        {performance && issue.meta && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-sm">Threshold</CardTitle>
                                </CardHeader>
                                <CardContent className="grid gap-2 text-sm sm:grid-cols-2">
                                    <div>
                                        <span className="text-muted-foreground">Name</span>
                                        <p className="font-mono break-all">{String(issue.meta.name)}</p>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Last breach</span>
                                        <p>
                                            {String(issue.meta.metric)} {formatMs(Number(issue.meta.value_ms))} over{' '}
                                            {String(issue.meta.window_minutes)} min (limit {formatMs(Number(issue.meta.threshold_ms))},{' '}
                                            {formatCount(Number(issue.meta.count))} samples)
                                        </p>
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {monitor && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-sm">
                                        Scheduled task <span className="font-mono">{monitor.job}</span>
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3 text-sm">
                                    <p className="text-muted-foreground">
                                        Schedule <span className="font-mono">{monitor.schedule ?? 'unknown'}</span> ({monitor.timezone}) · last run{' '}
                                        {ago(monitor.last_run_at)}
                                        {monitor.next_expected_at && ` · next expected ${format(new Date(monitor.next_expected_at), 'PPp')}`}
                                    </p>
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Scheduled</TableHead>
                                                <TableHead>Status</TableHead>
                                                <TableHead className="text-right">Exit</TableHead>
                                                <TableHead className="text-right">Duration</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {monitor.runs.map((run) => (
                                                <TableRow key={run.scheduled_at}>
                                                    <TableCell>{format(new Date(run.scheduled_at), 'PPp')}</TableCell>
                                                    <TableCell className={run.status === 'finished' ? '' : 'text-red-600 dark:text-red-400'}>
                                                        {run.status}
                                                    </TableCell>
                                                    <TableCell className="text-right tabular-nums">{run.exit_code ?? '—'}</TableCell>
                                                    <TableCell className="text-right tabular-nums">{formatMs(run.duration_ms)}</TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </CardContent>
                            </Card>
                        )}

                        {issue.trace_id && (
                            <TraceTimelineCard
                                traceId={issue.trace_id}
                                title="Latest occurrence · trace timeline"
                                highlightSpanId={issue.sample?.span_id ?? null}
                            />
                        )}

                        {occurrences.length > 0 && (
                            <Card className="gap-2">
                                <CardHeader>
                                    <CardTitle className="text-sm">Recent occurrences</CardTitle>
                                </CardHeader>
                                <CardContent className="px-0">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead className="pl-6">When</TableHead>
                                                <TableHead>Route / name</TableHead>
                                                <TableHead>Message</TableHead>
                                                <TableHead className="pr-6">Trace</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {occurrences.map((occurrence) => (
                                                <TableRow key={occurrence.id}>
                                                    <TableCell className="pl-6 whitespace-nowrap" title={occurrence.at}>
                                                        {ago(occurrence.at)}
                                                        {!occurrence.handled && (
                                                            <span className="ml-2 text-xs text-red-600 dark:text-red-400">unhandled</span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="max-w-48 truncate font-mono text-xs">
                                                        {occurrence.route_or_name ?? '—'}
                                                    </TableCell>
                                                    <TableCell className="max-w-72 truncate text-xs" title={occurrence.message}>
                                                        {occurrence.message}
                                                    </TableCell>
                                                    <TableCell className="pr-6">
                                                        {occurrence.trace_url ? (
                                                            <a href={occurrence.trace_url} className="font-mono text-xs hover:underline">
                                                                {occurrence.trace_id?.slice(0, 8)}…
                                                            </a>
                                                        ) : (
                                                            '—'
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </CardContent>
                            </Card>
                        )}

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-sm">Discussion</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {comments.length === 0 && <p className="text-muted-foreground text-sm">No comments yet.</p>}
                                {comments.map((c) => (
                                    <div key={c.id} className="rounded-md border p-3">
                                        <div className="mb-1 flex items-center justify-between text-xs">
                                            <span className="font-medium">{c.user}</span>
                                            <span className="text-muted-foreground flex items-center gap-2">
                                                {ago(c.at)}
                                                {c.can_delete && (
                                                    <button
                                                        type="button"
                                                        aria-label="Delete comment"
                                                        className="hover:text-destructive"
                                                        onClick={() =>
                                                            router.delete(route('insights.issues.comments.destroy', [issue.id, c.id]), options)
                                                        }
                                                    >
                                                        <Trash2 className="size-3.5" />
                                                    </button>
                                                )}
                                            </span>
                                        </div>
                                        <p className="text-sm whitespace-pre-wrap">{c.body}</p>
                                    </div>
                                ))}
                                {can.manage && (
                                    <form onSubmit={submitComment} className="space-y-2">
                                        <Textarea
                                            value={comment.data.body}
                                            onChange={(e) => comment.setData('body', e.target.value)}
                                            placeholder="Add a comment…"
                                            aria-label="Comment"
                                            rows={3}
                                        />
                                        <InputError message={comment.errors.body} />
                                        <Button type="submit" size="sm" disabled={comment.processing || comment.data.body.trim() === ''}>
                                            Comment
                                        </Button>
                                    </form>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    <aside className="space-y-4">
                        <Card>
                            <CardContent className="space-y-4 text-sm">
                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <p className="text-muted-foreground text-xs">Events</p>
                                        <p className="text-lg font-semibold tabular-nums">{formatCount(issue.occurrences)}</p>
                                    </div>
                                    <div>
                                        <p className="text-muted-foreground text-xs">Users</p>
                                        <p className="text-lg font-semibold tabular-nums">{formatCount(issue.affected_users)}</p>
                                    </div>
                                    {issue.kind === 'exception' && (
                                        <div className="col-span-2">
                                            <p className="text-muted-foreground text-xs">Unhandled</p>
                                            <p className="tabular-nums">{formatCount(issue.unhandled_occurrences)}</p>
                                        </div>
                                    )}
                                </div>
                                <div className="space-y-1">
                                    <p className="text-muted-foreground text-xs">Assignee</p>
                                    {can.manage ? (
                                        <Select value={issue.assignee?.id ?? UNASSIGNED} onValueChange={setAssignee}>
                                            <SelectTrigger aria-label="Assignee">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value={UNASSIGNED}>Unassigned</SelectItem>
                                                {members.map((member) => (
                                                    <SelectItem key={member.id} value={member.id}>
                                                        {member.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    ) : (
                                        <p>{issue.assignee?.name ?? 'Unassigned'}</p>
                                    )}
                                </div>
                                <div className="space-y-1">
                                    <p className="text-muted-foreground text-xs">Priority</p>
                                    {can.manage ? (
                                        <Select value={issue.priority} onValueChange={setPriority}>
                                            <SelectTrigger aria-label="Priority">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {priorities.map((priority) => (
                                                    <SelectItem key={priority} value={priority} className="capitalize">
                                                        {priority}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    ) : (
                                        <p className="capitalize">{issue.priority}</p>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {(links.trace || links.siteLogs) && (
                            <Card>
                                <CardContent className="flex flex-col gap-2">
                                    {links.trace && (
                                        <Button size="sm" variant="outline" asChild>
                                            <a href={links.trace}>
                                                <Activity /> Open trace
                                            </a>
                                        </Button>
                                    )}
                                    {links.traceLogs && (
                                        <Button size="sm" variant="outline" asChild>
                                            <a href={links.traceLogs}>
                                                <ScrollText /> Logs for this trace
                                            </a>
                                        </Button>
                                    )}
                                    {links.siteLogs && (
                                        <Button size="sm" variant="outline" asChild>
                                            <a href={links.siteLogs}>
                                                <ScrollText /> Site logs around last occurrence
                                            </a>
                                        </Button>
                                    )}
                                    {links.grafanaTrace && (
                                        <Button size="sm" variant="ghost" asChild>
                                            <a href={links.grafanaTrace} target="_blank" rel="noreferrer">
                                                <ExternalLink /> Grafana Explore
                                            </a>
                                        </Button>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        <Card className="gap-2">
                            <CardHeader>
                                <CardTitle className="text-sm">Activity</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ol className="space-y-2 text-xs">
                                    {activity.map((entry) => (
                                        <li key={entry.id} className="flex justify-between gap-2">
                                            <span>{describeActivity(entry, members)}</span>
                                            <span className="text-muted-foreground shrink-0" title={entry.at}>
                                                {ago(entry.at)}
                                            </span>
                                        </li>
                                    ))}
                                </ol>
                            </CardContent>
                        </Card>
                    </aside>
                </div>
            </div>
        </AppLayout>
    );
}
