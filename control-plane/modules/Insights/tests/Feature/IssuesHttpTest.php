<?php

use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Domain\Models\AuditEntry;
use Kiln\Insights\Contracts\IssuePriority;
use Kiln\Insights\Contracts\IssueStatus;
use Kiln\Insights\Contracts\SiteNameResolver;
use Kiln\Insights\Domain\Models\Issue;
use Kiln\Insights\Domain\Models\IssueComment;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    insights_ingest($this->organization->id, [
        insights_exception(),
        insights_exception(['type' => 'LogicException', 'message' => 'Broken invariant', 'site_id' => INSIGHTS_OTHER_SITE]),
        insights_aggregate(),
        insights_aggregate(['event_type' => 'query', 'name' => 'select * from `orders`', 'max_ms' => 2500]),
        insights_aggregate(['event_type' => 'job', 'name' => 'App\\Jobs\\Sync', 'count' => 20, 'errors' => 2]),
        insights_aggregate(['event_type' => 'cache', 'name' => 'hit:redis', 'count' => 90]),
        insights_aggregate(['event_type' => 'cache', 'name' => 'miss', 'count' => 10]),
    ]);
    $this->issue = Issue::query()->where('site_id', INSIGHTS_SITE)->sole();
});

it('lists sites with 24h stats', function () {
    $this->get('/insights')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Insights/Index', false)
        ->has('sites', 2)
        ->where('openIssues', 2)
        ->where('sites', fn ($sites) => collect($sites)->firstWhere('id', INSIGHTS_SITE)['requests_24h'] === 100
            && collect($sites)->firstWhere('id', INSIGHTS_SITE)['exceptions_24h'] === 1
            && collect($sites)->firstWhere('id', INSIGHTS_SITE)['open_issues'] === 1));
});

it('renders the app overview from aggregates with telemetry deep links', function () {
    $this->get('/insights/sites/'.INSIGHTS_SITE.'?range=1h')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Insights/Overview', false)
        ->where('site.id', INSIGHTS_SITE)
        ->where('overview.totals.requests', 100)
        ->where('overview.totals.request_errors', 1)
        ->where('overview.totals.request_p95_ms', 120)
        ->where('overview.totals.jobs', 20)
        ->where('overview.totals.failed_jobs', 2)
        ->where('overview.totals.exceptions_unhandled', 1)
        ->where('overview.totals.exceptions_handled', 0)
        ->where('overview.totals.cache_hit_ratio', 0.9)
        ->has('overview.series', 60)
        ->where('overview.routes.0.name', 'GET /products')
        ->where('overview.queries.0.max_ms', 2500)
        ->where('overview.jobs.0.errors', 2)
        ->has('overview.issues', 1)
        ->where('links.logs', fn ($url) => str_contains($url, '/telemetry/logs') && str_contains($url, INSIGHTS_SITE))
        ->where('links.traces', fn ($url) => str_contains($url, '/telemetry/traces')));

    $this->get('/insights/sites/'.INSIGHTS_SITE.'?range=5y')->assertSessionHasErrors('range');
    $this->get('/insights/sites/not-a-site')->assertNotFound();
});

it('uses the bound SiteNameResolver for names', function () {
    app()->instance(SiteNameResolver::class, new class implements SiteNameResolver
    {
        public function name(string $siteId): string
        {
            return "site-{$siteId}";
        }

        public function names(array $siteIds): array
        {
            return collect($siteIds)->mapWithKeys(fn ($id) => [$id => "site-{$id}"])->all();
        }
    });

    $this->get('/insights/issues')->assertInertia(fn ($page) => $page->where('issues.data.0.site_name', fn ($name) => str_starts_with($name, 'site-')));
});

it('filters, searches and sorts the issue list', function () {
    $this->get('/insights/issues')->assertOk()->assertInertia(fn ($page) => $page->component('Insights/Issues', false)->has('issues.data', 2)->where('filters.status', 'open'));
    $this->get('/insights/issues?site='.INSIGHTS_SITE)->assertInertia(fn ($page) => $page->has('issues.data', 1)->where('issues.data.0.id', $this->issue->id));
    $this->get('/insights/issues?search=invariant')->assertInertia(fn ($page) => $page->has('issues.data', 1)->where('issues.data.0.title', 'LogicException: Broken invariant'));
    $this->get('/insights/issues?search=%25')->assertInertia(fn ($page) => $page->has('issues.data', 0));
    $this->get('/insights/issues?status=resolved')->assertInertia(fn ($page) => $page->has('issues.data', 0));
    $this->get('/insights/issues?kind=performance')->assertInertia(fn ($page) => $page->has('issues.data', 0));
    $this->get('/insights/issues?sort=occurrences&status=all')->assertInertia(fn ($page) => $page->has('issues.data', 2));
    $this->get('/insights/issues?assignee=me')->assertInertia(fn ($page) => $page->has('issues.data', 0));
    $this->get('/insights/issues?sort=random')->assertSessionHasErrors('sort');
});

it('shows the issue with its stack trace, occurrences and trace links', function () {
    $this->get("/insights/issues/{$this->issue->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Insights/Issue', false)
        ->where('issue.id', $this->issue->id)
        ->where('issue.frames.0.file', 'app/Services/Checkout.php')
        ->where('issue.frames.0.in_app', true)
        ->where('issue.frames.2.in_app', false)
        ->where('issue.trace_id', $this->issue->last_trace_id)
        ->has('occurrences', 1)
        ->where('occurrences.0.trace_url', fn ($url) => str_contains($url, "/telemetry/traces/{$this->issue->last_trace_id}"))
        ->has('timeline', 24)
        ->where('links.trace', fn ($url) => str_contains($url, $this->issue->last_trace_id))
        ->where('links.traceLogs', fn ($url) => str_contains($url, $this->issue->last_trace_id))
        ->where('can.manage', true)
        ->has('members', 2));
});

it('supports assigning, prioritising and commenting', function () {
    [$teammate] = memberOf($this->organization, Role::Viewer);
    [$stranger] = memberOf();

    $this->put("/insights/issues/{$this->issue->id}/assignee", ['assignee_id' => $teammate->id])->assertSessionHasNoErrors();
    $this->put("/insights/issues/{$this->issue->id}/assignee", ['assignee_id' => $stranger->id])->assertSessionHasErrors('assignee_id');
    $this->put("/insights/issues/{$this->issue->id}/priority", ['priority' => 'urgent'])->assertSessionHasNoErrors();
    $this->put("/insights/issues/{$this->issue->id}/priority", ['priority' => 'meh'])->assertSessionHasErrors('priority');
    $this->post("/insights/issues/{$this->issue->id}/comments", ['body' => 'Looking into it'])->assertSessionHasNoErrors();
    $this->post("/insights/issues/{$this->issue->id}/comments", ['body' => ''])->assertSessionHasErrors('body');

    expect($this->issue->refresh())
        ->assignee_id->toBe($teammate->id)
        ->priority->toBe(IssuePriority::Urgent)
        ->and($this->issue->comments()->sole()->body)->toBe('Looking into it')
        ->and($this->issue->activities()->pluck('type')->all())->toBe(['opened', 'assigned', 'priority', 'commented'])
        ->and(AuditEntry::query()->where('action', 'insights.issue.assigned')->exists())->toBeTrue();

    $this->get('/insights/issues?assignee='.$teammate->id)->assertInertia(fn ($page) => $page->has('issues.data', 1)->where('issues.data.0.assignee.name', $teammate->name));
    $this->put("/insights/issues/{$this->issue->id}/assignee", ['assignee_id' => null])->assertSessionHasNoErrors();
    expect($this->issue->refresh()->assignee_id)->toBeNull();

    // Only the author may delete a comment.
    $comment = IssueComment::query()->sole();
    [$colleague] = memberOf($this->organization, Role::Developer);
    $this->actingAs($colleague)->delete("/insights/issues/{$this->issue->id}/comments/{$comment->id}")->assertForbidden();
    $this->actingAs($this->user)->delete("/insights/issues/{$this->issue->id}/comments/{$comment->id}")->assertRedirect();
    expect(IssueComment::query()->count())->toBe(0);
});

it('reopens ignored issues and records the audit trail', function () {
    $this->put("/insights/issues/{$this->issue->id}/status", ['status' => 'ignored'])->assertRedirect();
    $this->put("/insights/issues/{$this->issue->id}/status", ['status' => 'open'])->assertRedirect();
    $this->put("/insights/issues/{$this->issue->id}/status", ['status' => 'nope'])->assertSessionHasErrors('status');

    expect($this->issue->refresh()->status)->toBe(IssueStatus::Open)
        ->and(AuditEntry::query()->whereIn('action', ['insights.issue.ignored', 'insights.issue.open'])->count())->toBe(2);
});

it('enforces permissions and organization isolation', function () {
    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer);
    $this->get("/insights/issues/{$this->issue->id}")->assertOk()->assertInertia(fn ($page) => $page->where('can.manage', false));
    $this->put("/insights/issues/{$this->issue->id}/status", ['status' => 'resolved'])->assertForbidden();
    $this->post("/insights/issues/{$this->issue->id}/comments", ['body' => 'hi'])->assertForbidden();

    actingAsMember(Role::Owner);
    $this->get("/insights/issues/{$this->issue->id}")->assertNotFound();
    $this->put("/insights/issues/{$this->issue->id}/priority", ['priority' => 'high'])->assertNotFound();
    $this->get('/insights/issues')->assertInertia(fn ($page) => $page->has('issues.data', 0));
    $this->get('/insights')->assertInertia(fn ($page) => $page->has('sites', 0));
    $this->get('/insights/sites/'.INSIGHTS_SITE)->assertInertia(fn ($page) => $page->where('overview.totals.requests', 0));

    auth()->logout();
    $this->get('/insights')->assertRedirect('/login');
});
