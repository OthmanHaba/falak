<?php

namespace Falak\Insights\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\Data\UserData;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Identity\Contracts\OrganizationDirectory;
use Falak\Insights\Application\Actions\AssignIssue;
use Falak\Insights\Application\Actions\ChangeIssueStatus;
use Falak\Insights\Application\Actions\SetIssuePriority;
use Falak\Insights\Application\Queries\IssueTimeline;
use Falak\Insights\Contracts\IssueKind;
use Falak\Insights\Contracts\IssuePriority;
use Falak\Insights\Contracts\IssueStatus;
use Falak\Insights\Contracts\SiteNameResolver;
use Falak\Insights\Domain\Models\ExceptionOccurrence;
use Falak\Insights\Domain\Models\HeartbeatMonitor;
use Falak\Insights\Domain\Models\HeartbeatRun;
use Falak\Insights\Domain\Models\Issue;
use Falak\Insights\Domain\Models\IssueActivity;
use Falak\Insights\Domain\Models\IssueComment;
use Falak\Insights\Domain\Support\StackFrame;
use Falak\Insights\Domain\Support\StackTrace;
use Falak\Kernel\Http\Controller;
use Falak\Telemetry\Contracts\TelemetryLinks;

final class IssueController extends Controller
{
    public const SORTS = ['last_seen' => 'last_seen_at', 'first_seen' => 'first_seen_at', 'occurrences' => 'occurrences', 'users' => 'affected_users'];

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly OrganizationDirectory $directory,
        private readonly SiteNameResolver $sites,
    ) {}

    public function index(Request $request): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'insights.view');

        $filters = $request->validate([
            'status' => ['nullable', Rule::in([...array_column(IssueStatus::cases(), 'value'), 'all'])],
            'kind' => ['nullable', Rule::enum(IssueKind::class)],
            'priority' => ['nullable', Rule::enum(IssuePriority::class)],
            'site' => ['nullable', 'string', 'size:26'],
            'assignee' => ['nullable', 'string', 'max:26'],
            'search' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', Rule::in(array_keys(self::SORTS))],
        ]);
        $status = $filters['status'] ?? IssueStatus::Open->value;
        $userId = (string) $request->user()?->getAuthIdentifier();

        $filtered = fn () => Issue::query()
            ->where('organization_id', $organizationId)
            ->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($filters['priority'] ?? null, fn ($q, $priority) => $q->where('priority', $priority))
            ->when($filters['site'] ?? null, fn ($q, $site) => $q->where('site_id', strtolower($site)))
            ->when($filters['assignee'] ?? null, fn ($q, $assignee) => match ($assignee) {
                'me' => $q->where('assignee_id', $userId),
                'none' => $q->whereNull('assignee_id'),
                default => $q->where('assignee_id', $assignee),
            })
            ->when($filters['search'] ?? null, function ($q, $search) {
                $like = '%'.addcslashes($search, '%_\\').'%';

                $q->where(fn ($q) => $q->whereRaw("title LIKE ? ESCAPE '\\'", [$like])->orWhereRaw("culprit LIKE ? ESCAPE '\\'", [$like]));
            });

        $issues = $filtered()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc(self::SORTS[$filters['sort'] ?? 'last_seen'])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $names = $this->sites->names(array_values(array_unique(array_filter($issues->getCollection()->pluck('site_id')->all()))));
        $members = $this->members($organizationId);
        $sparklines = $this->sparklines($issues->getCollection()->pluck('id')->all());
        $counts = $filtered()->groupBy('status')->selectRaw('status, COUNT(*) AS total')->pluck('total', 'status');

        return Inertia::render('Insights/Issues', [
            'issues' => $issues->through(fn (Issue $issue) => [...$this->summary($issue, $names, $members), 'sparkline' => $sparklines[$issue->id] ?? null]),
            'filters' => [...array_filter($filters), 'status' => $status],
            'counts' => [
                ...array_map(fn (IssueStatus $s) => (int) ($counts[$s->value] ?? 0), array_combine(array_column(IssueStatus::cases(), 'value'), IssueStatus::cases())),
                'all' => (int) $counts->sum(),
            ],
            'members' => array_values($members),
            'sites' => $this->sites->names(DB::table('insights_sites')->where('organization_id', $organizationId)->pluck('site_id')->all()),
            'priorities' => array_column(IssuePriority::cases(), 'value'),
            'can' => ['manage' => $this->access->can($request->user(), $organizationId, 'insights.manage')],
        ]);
    }

    /** PUT /insights/issues/bulk/status {ids[], status}: resolve / ignore / reopen several issues at once. */
    public function bulkStatus(Request $request, ChangeIssueStatus $change): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['string', 'size:26'],
            'status' => ['required', Rule::enum(IssueStatus::class)],
        ]);
        $status = IssueStatus::from($data['status']);
        $userId = (string) $request->user()?->getAuthIdentifier();

        $issues = Issue::query()->where('organization_id', $organizationId)->whereIn('id', array_map('strtolower', $data['ids']))->get();
        abort_if($issues->isEmpty(), 404);

        foreach ($issues as $issue) {
            $this->authorize('update', $issue);
        }

        foreach ($issues as $issue) {
            if ($issue->status !== $status) {
                $change($issue, $status, $userId);
            }
        }

        $label = match ($status) {
            IssueStatus::Resolved => 'resolved',
            IssueStatus::Ignored => 'ignored',
            IssueStatus::Open => 'reopened',
        };

        return back()->with('success', $issues->count() === 1 ? "Issue {$label}." : "{$issues->count()} issues {$label}.");
    }

    public function show(Request $request, Issue $issue, IssueTimeline $timeline, TelemetryLinks $links): Response
    {
        $this->authorize('view', $issue);
        $members = $this->members($issue->organization_id);

        $sample = $issue->sample ?? [];
        $frames = array_map(fn (StackFrame $frame) => $frame->toArray(), StackTrace::parse($sample['stacktrace'] ?? null));

        $occurrences = $issue->kind === IssueKind::Exception
            ? ExceptionOccurrence::query()->where('issue_id', $issue->id)->orderByDesc('occurred_at')->orderByDesc('id')->limit(25)->get()
                ->map(fn (ExceptionOccurrence $o) => [
                    'id' => $o->id,
                    'at' => $o->occurred_at->toIso8601String(),
                    'message' => $o->message,
                    'handled' => $o->handled,
                    'route_or_name' => $o->route_or_name,
                    'server_id' => $o->server_id,
                    'trace_id' => $o->trace_id,
                    'trace_url' => $o->trace_id ? $links->trace($o->trace_id) : null,
                ])->all()
            : [];

        $monitor = null;

        if ($issue->kind === IssueKind::Heartbeat && isset($issue->meta['monitor_id'])) {
            $model = HeartbeatMonitor::query()->where('organization_id', $issue->organization_id)->find($issue->meta['monitor_id']);
            $monitor = $model ? [
                'id' => $model->id,
                'job' => $model->job,
                'schedule' => $model->schedule,
                'timezone' => $model->timezone,
                'last_status' => $model->last_status,
                'last_run_at' => $model->last_run_at?->toIso8601String(),
                'next_expected_at' => $model->next_expected_at?->toIso8601String(),
                'runs' => $model->runs()->orderByDesc('scheduled_at')->limit(20)->get()->map(fn (HeartbeatRun $run) => [
                    'status' => $run->status,
                    'exit_code' => $run->exit_code,
                    'duration_ms' => $run->duration_ms,
                    'scheduled_at' => $run->scheduled_at->toIso8601String(),
                    'at' => $run->at->toIso8601String(),
                ])->all(),
            ] : null;
        }

        $traceId = $issue->last_trace_id;

        return Inertia::render('Insights/Issue', [
            'issue' => [
                ...$this->summary($issue, $issue->site_id ? [$issue->site_id => $this->sites->name($issue->site_id)] : [], $members),
                'exception_type' => $issue->exception_type,
                'event_type' => $issue->event_type,
                'unhandled_occurrences' => $issue->unhandled_occurrences,
                'regressions' => $issue->regressions,
                'regressed_at' => $issue->regressed_at?->toIso8601String(),
                'resolved_at' => $issue->resolved_at?->toIso8601String(),
                'resolved_by' => $issue->resolved_by ? ($members[$issue->resolved_by]['name'] ?? null) : null,
                'server_id' => $issue->server_id,
                'sample' => $sample === [] ? null : $sample,
                'meta' => $issue->meta,
                'frames' => $frames,
                'trace_id' => $traceId,
            ],
            'occurrences' => $occurrences,
            'timeline' => $timeline($issue),
            'monitor' => $monitor,
            'activity' => $issue->activities()->limit(200)->get()->map(fn (IssueActivity $a) => [
                'id' => $a->id,
                'type' => $a->type,
                'user' => $a->user_id ? ($members[$a->user_id]['name'] ?? 'Former member') : null,
                'data' => $a->data,
                'at' => $a->created_at->toIso8601String(),
            ]),
            'comments' => $issue->comments()->get()->map(fn (IssueComment $c) => [
                'id' => $c->id,
                'body' => $c->body,
                'user' => $members[$c->user_id]['name'] ?? 'Former member',
                'user_id' => $c->user_id,
                'at' => $c->created_at->toIso8601String(),
                'can_delete' => $c->user_id === $request->user()?->getAuthIdentifier(),
            ]),
            'members' => array_values($members),
            'links' => [
                'trace' => $traceId ? $links->trace($traceId) : null,
                'traceLogs' => $traceId ? $links->logs(['trace_id' => $traceId]) : null,
                'grafanaTrace' => $traceId ? $links->grafanaTrace($traceId) : null,
                'siteLogs' => $issue->site_id ? $links->logs(['site_id' => $issue->site_id], $issue->last_seen_at->subMinutes(15), $issue->last_seen_at->addMinutes(5)) : null,
            ],
            'priorities' => array_column(IssuePriority::cases(), 'value'),
            'can' => ['manage' => $request->user()?->can('update', $issue) ?? false],
        ]);
    }

    public function status(Request $request, Issue $issue, ChangeIssueStatus $change): RedirectResponse
    {
        $this->authorize('update', $issue);
        $data = $request->validate(['status' => ['required', Rule::enum(IssueStatus::class)]]);

        $change($issue, IssueStatus::from($data['status']), (string) $request->user()?->getAuthIdentifier());

        return back();
    }

    public function assign(Request $request, Issue $issue, AssignIssue $assign): RedirectResponse
    {
        $this->authorize('update', $issue);
        $data = $request->validate(['assignee_id' => ['nullable', 'string', 'size:26']]);

        $assign($issue, $data['assignee_id'] ?? null, (string) $request->user()?->getAuthIdentifier());

        return back();
    }

    public function priority(Request $request, Issue $issue, SetIssuePriority $set): RedirectResponse
    {
        $this->authorize('update', $issue);
        $data = $request->validate(['priority' => ['required', Rule::enum(IssuePriority::class)]]);

        $set($issue, IssuePriority::from($data['priority']), (string) $request->user()?->getAuthIdentifier());

        return back();
    }

    /**
     * @param  array<string, string>  $siteNames
     * @param  array<string, array{id: string, name: string, email: string}>  $members
     * @return array<string, mixed>
     */
    private function summary(Issue $issue, array $siteNames, array $members): array
    {
        return [
            'id' => $issue->id,
            'kind' => $issue->kind->value,
            'status' => $issue->status->value,
            'priority' => $issue->priority->value,
            'title' => $issue->title,
            'culprit' => $issue->culprit,
            'site_id' => $issue->site_id,
            'site_name' => $issue->site_id ? ($siteNames[$issue->site_id] ?? $issue->site_id) : null,
            'occurrences' => $issue->occurrences,
            'affected_users' => $issue->affected_users,
            'first_seen_at' => $issue->first_seen_at->toIso8601String(),
            'last_seen_at' => $issue->last_seen_at->toIso8601String(),
            'assignee' => $issue->assignee_id ? ($members[$issue->assignee_id] ?? ['id' => $issue->assignee_id, 'name' => 'Former member', 'email' => '']) : null,
            'handled' => $issue->kind === IssueKind::Exception ? $issue->unhandled_occurrences === 0 : null,
        ];
    }

    /**
     * Occurrences per hour over the last 24 hours for each exception issue (24 buckets, oldest first).
     *
     * @param  list<string>  $issueIds
     * @return array<string, list<int>>
     */
    private function sparklines(array $issueIds): array
    {
        if ($issueIds === []) {
            return [];
        }

        $end = CarbonImmutable::now('UTC')->startOfHour()->addHour();
        $start = $end->subHours(24);
        $lines = [];

        $rows = DB::table('insights_exceptions')
            ->whereIn('issue_id', $issueIds)
            ->where('minute', '>=', $start)
            ->where('minute', '<', $end)
            ->groupBy('issue_id', 'minute')
            ->selectRaw('issue_id, minute, COUNT(*) AS total')
            ->get();

        foreach ($rows as $row) {
            $bucket = intdiv((int) $start->diffInMinutes(CarbonImmutable::parse($row->minute, 'UTC'), true), 60);
            $lines[$row->issue_id] ??= array_fill(0, 24, 0);
            $lines[$row->issue_id][min(23, $bucket)] += (int) $row->total;
        }

        return $lines;
    }

    /**
     * @return array<string, array{id: string, name: string, email: string}>
     */
    private function members(string $organizationId): array
    {
        $members = [];

        foreach ($this->directory->members($organizationId) as $user) {
            /** @var UserData $user */
            $members[$user->id] = ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];
        }

        return $members;
    }
}
