<?php

use Illuminate\Support\Facades\Event;
use Kiln\Identity\Contracts\Role;
use Kiln\Insights\Application\Actions\EvaluateThresholds;
use Kiln\Insights\Contracts\IssueKind;
use Kiln\Insights\Contracts\IssueStatus;
use Kiln\Insights\Domain\Models\Issue;
use Kiln\Insights\Domain\Models\Threshold;
use Kiln\Insights\Events\IssueOpened;
use Kiln\Insights\Events\IssueRegressed;
use Kiln\Insights\Events\ThresholdBreached;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    $this->travelTo(now()->startOfMinute()->addSeconds(10));
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->threshold = fn (array $overrides = []) => Threshold::query()->create(array_replace([
        'organization_id' => $this->organization->id,
        'site_id' => INSIGHTS_SITE,
        'event_type' => 'request',
        'metric' => 'p95',
        'threshold_ms' => 500,
        'window_minutes' => 5,
        'min_count' => 1,
        'enabled' => true,
    ], $overrides));
    $this->minutesAgo = fn (int $minutes) => now()->subMinutes($minutes)->startOfMinute()->toIso8601ZuluString();
});

it('opens one performance issue per breaching name and dispatches ThresholdBreached once', function () {
    Event::fake([ThresholdBreached::class, IssueOpened::class]);
    $threshold = ($this->threshold)();

    insights_ingest($this->organization->id, [
        // GET /slow: weighted p95 = (900*100 + 300*300) / 400 = 450 → below 500 … plus a very slow minute
        insights_aggregate(['name' => 'GET /slow', 'count' => 100, 'p95_ms' => 900, 'minute' => ($this->minutesAgo)(1)]),
        insights_aggregate(['name' => 'GET /slow', 'count' => 100, 'p95_ms' => 700, 'minute' => ($this->minutesAgo)(2)]),
        insights_aggregate(['name' => 'GET /fast', 'count' => 1000, 'p95_ms' => 80, 'minute' => ($this->minutesAgo)(1)]),
        // outside the 5 minute window
        insights_aggregate(['name' => 'GET /old', 'count' => 10, 'p95_ms' => 5000, 'minute' => ($this->minutesAgo)(10)]),
        // other event type
        insights_aggregate(['name' => 'App\\Jobs\\Sync', 'event_type' => 'job', 'count' => 10, 'p95_ms' => 5000, 'minute' => ($this->minutesAgo)(1)]),
    ]);

    expect(app(EvaluateThresholds::class)())->toBe(1);

    $issue = Issue::query()->sole();
    expect($issue->kind)->toBe(IssueKind::Performance)
        ->and($issue->title)->toBe('Slow route: GET /slow')
        ->and($issue->culprit)->toBe('p95 over 5 min above 500 ms')
        ->and($issue->meta)->toMatchArray(['name' => 'GET /slow', 'value_ms' => 800.0, 'threshold_ms' => 500.0, 'count' => 200])
        ->and($threshold->refresh()->last_breached_at)->not->toBeNull();

    Event::assertDispatched(ThresholdBreached::class, fn (ThresholdBreached $e) => $e->issueId === $issue->id
        && $e->thresholdId === $threshold->id
        && $e->name === 'GET /slow'
        && $e->valueMs === 800.0
        && $e->metric === 'p95'
        && $e->windowMinutes === 5);
    Event::assertDispatched(IssueOpened::class, fn ($e) => $e->kind === 'performance');

    // Still breaching next minute: same issue, no new events.
    $this->travel(1)->minutes();
    app(EvaluateThresholds::class)();

    expect(Issue::query()->sole()->occurrences)->toBe(2);
    Event::assertDispatchedTimes(ThresholdBreached::class, 1);
});

it('supports max metric, name patterns and a minimum sample count', function () {
    Event::fake([ThresholdBreached::class]);
    ($this->threshold)(['event_type' => 'query', 'metric' => 'max', 'threshold_ms' => 1000, 'name_pattern' => 'select * from `orders`*', 'min_count' => 5]);

    insights_ingest($this->organization->id, [
        insights_aggregate(['event_type' => 'query', 'name' => 'select * from `orders` where id = ?', 'count' => 5, 'p95_ms' => 100, 'max_ms' => 1500]),
        insights_aggregate(['event_type' => 'query', 'name' => 'select * from `orders_archive`', 'count' => 2, 'p95_ms' => 100, 'max_ms' => 9000]),
        insights_aggregate(['event_type' => 'query', 'name' => 'select * from `users`', 'count' => 50, 'max_ms' => 9000]),
        insights_aggregate(['event_type' => 'query', 'name' => 'select * from `orders` limit 100%', 'count' => 50, 'max_ms' => 900]),
    ]);

    expect(app(EvaluateThresholds::class)())->toBe(1)
        ->and(Issue::query()->sole()->meta['name'])->toBe('select * from `orders` where id = ?');
});

it('treats LIKE metacharacters in patterns literally', function () {
    ($this->threshold)(['name_pattern' => 'GET /100%_done']);

    insights_ingest($this->organization->id, [
        insights_aggregate(['name' => 'GET /100%_done', 'p95_ms' => 900]),
        insights_aggregate(['name' => 'GET /100xxdone', 'p95_ms' => 900]),
    ]);

    expect(app(EvaluateThresholds::class)())->toBe(1);
});

it('ignores other organizations, other sites and disabled thresholds', function () {
    [, $other] = memberOf();
    ($this->threshold)();
    ($this->threshold)(['enabled' => false, 'site_id' => INSIGHTS_OTHER_SITE]);

    insights_ingest($other->id, [insights_aggregate(['p95_ms' => 9000])]);
    insights_ingest($this->organization->id, [insights_aggregate(['site_id' => INSIGHTS_OTHER_SITE, 'p95_ms' => 9000])]);

    expect(app(EvaluateThresholds::class)())->toBe(0)->and(Issue::query()->count())->toBe(0);
});

it('regresses a resolved performance issue when the threshold is breached again', function () {
    Event::fake([ThresholdBreached::class, IssueRegressed::class]);
    ($this->threshold)();
    insights_ingest($this->organization->id, [insights_aggregate(['p95_ms' => 900])]);
    app(EvaluateThresholds::class)();
    $issue = Issue::query()->sole();

    $this->put("/insights/issues/{$issue->id}/status", ['status' => 'resolved'])->assertRedirect();
    $this->travel(1)->minutes();
    app(EvaluateThresholds::class)();

    expect($issue->refresh()->status)->toBe(IssueStatus::Open);
    Event::assertDispatched(IssueRegressed::class);
    Event::assertDispatchedTimes(ThresholdBreached::class, 2);
});

it('manages thresholds per site with validation and permissions', function () {
    $this->post('/insights/sites/'.INSIGHTS_SITE.'/thresholds', [
        'event_type' => 'job', 'metric' => 'p95', 'threshold_ms' => 2000, 'window_minutes' => 10, 'name_pattern' => 'App\\Jobs\\*',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $threshold = Threshold::query()->sole();
    expect($threshold->organization_id)->toBe($this->organization->id)->and($threshold->name_pattern)->toBe('App\\Jobs\\*');

    $this->put("/insights/thresholds/{$threshold->id}", ['event_type' => 'job', 'metric' => 'max', 'threshold_ms' => 5000, 'window_minutes' => 15, 'enabled' => false])->assertRedirect();
    expect($threshold->refresh())->metric->value->toBe('max')->threshold_ms->toBe(5000.0)->enabled->toBeFalse();

    $this->post('/insights/sites/'.INSIGHTS_SITE.'/thresholds', ['event_type' => 'mail', 'metric' => 'avg', 'threshold_ms' => 0, 'window_minutes' => 0])
        ->assertSessionHasErrors(['event_type', 'metric', 'threshold_ms', 'window_minutes']);

    $this->get('/insights/sites/'.INSIGHTS_SITE.'/settings')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Insights/Settings', false)
        ->has('thresholds', 1)
        ->where('can.manage', true));

    // Viewers can look but not change; other organizations cannot see it at all.
    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer)->delete("/insights/thresholds/{$threshold->id}")->assertForbidden();
    $this->actingAs($viewer)->post('/insights/sites/'.INSIGHTS_SITE.'/thresholds', ['event_type' => 'job', 'metric' => 'p95', 'threshold_ms' => 1, 'window_minutes' => 1])->assertForbidden();
    actingAsMember();
    $this->delete("/insights/thresholds/{$threshold->id}")->assertNotFound();

    $this->actingAs($this->user)->delete("/insights/thresholds/{$threshold->id}")->assertRedirect();
    expect(Threshold::query()->count())->toBe(0);
});
