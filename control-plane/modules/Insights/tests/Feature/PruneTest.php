<?php

use Illuminate\Support\Facades\DB;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Insights\Application\Actions\PruneInsights;
use Falak\Insights\Domain\Models\Issue;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Owner);
});

it('prunes volume rows older than the retention window and keeps issues', function () {
    $old = now()->subDays(40);
    insights_ingest($this->organization->id, [
        insights_exception(['at' => $old->toIso8601ZuluString()]),
        insights_aggregate(['minute' => $old->toIso8601ZuluString()]),
        insights_heartbeat(['scheduled_at' => $old->toIso8601ZuluString(), 'at' => $old->toIso8601ZuluString()]),
    ]);
    insights_ingest($this->organization->id, [insights_exception(), insights_aggregate(), insights_heartbeat()]);
    config(['insights.prune_chunk' => 100]);

    expect(app(PruneInsights::class)())->toBe(['insights_exceptions' => 1, 'insights_aggregates' => 1, 'insights_heartbeat_runs' => 1])
        ->and(DB::table('insights_exceptions')->count())->toBe(1)
        ->and(DB::table('insights_aggregates')->count())->toBe(1)
        ->and(DB::table('insights_heartbeat_runs')->count())->toBe(1)
        ->and(Issue::query()->sole()->occurrences)->toBe(2);

    // Configurable retention.
    expect(app(PruneInsights::class)(1)['insights_exceptions'])->toBe(0);
    config(['insights.retention_days' => 60]);
    expect(app(PruneInsights::class)())->toBe(['insights_exceptions' => 0, 'insights_aggregates' => 0, 'insights_heartbeat_runs' => 0]);
});

it('prunes in chunks until everything old is gone', function () {
    $old = now()->subDays(31)->toIso8601ZuluString();
    config(['insights.prune_chunk' => 100]);
    insights_ingest($this->organization->id, array_map(fn (int $i) => insights_aggregate(['name' => "GET /{$i}", 'minute' => $old]), range(1, 250)));

    expect(app(PruneInsights::class)()['insights_aggregates'])->toBe(250);
});

it('deletes all insights data of a deleted organization', function () {
    insights_ingest($this->organization->id, [insights_exception(), insights_aggregate(), insights_heartbeat()]);
    [, $other] = memberOf();
    insights_ingest($other->id, [insights_exception()]);

    OrganizationDeleted::dispatch($this->organization->id);

    foreach (['insights_issues', 'insights_exceptions', 'insights_aggregates', 'insights_heartbeat_monitors', 'insights_sites'] as $table) {
        expect(DB::table($table)->where('organization_id', $this->organization->id)->count())->toBe(0, $table);
    }

    expect(DB::table('insights_heartbeat_runs')->count())->toBe(0)
        ->and(DB::table('insights_issue_users')->count())->toBe(1)
        ->and(Issue::query()->where('organization_id', $other->id)->count())->toBe(1);
});
