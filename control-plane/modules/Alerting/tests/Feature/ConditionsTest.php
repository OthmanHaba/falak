<?php

namespace Falak\Alerting\Tests\Feature;

use Falak\Alerting\Application\AlertMessage;
use Falak\Alerting\Application\Jobs\PruneAlerting;
use Falak\Alerting\Contracts\AlertConditions;
use Falak\Alerting\Contracts\Alerts;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Falak\Alerting\Domain\Enums\AlertOutcome;
use Falak\Alerting\Domain\Models\Alert;
use Falak\Alerting\Domain\Models\Condition;
use Falak\Alerting\Domain\Models\DedupState;
use Falak\Alerting\Domain\Models\Notification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(fn () => Http::response('ok'));
    [$this->owner, $this->organization] = memberOf();
    $this->slack = alerting_channel($this->organization->id);
    alerting_without_default_pack($this->organization->id);
    alerting_rule($this->organization->id, ['*'], [$this->slack]);
    $this->conditions = app(AlertConditions::class);
    $this->alert = fn () => new AlertData($this->organization->id, 'servers.disk_usage', Severity::Warning, 'Disk / on web-1 is 85% full', 'Free space.', '/servers/s1', context: ['mount' => '/']);
});

it('raises once while a condition holds and resolves it when it clears', function () {
    foreach (range(1, 5) as $i) {
        $this->conditions->observe($this->organization->id, 'servers.disk:s1:/:warning', true, $this->alert);
    }

    $alert = Alert::query()->sole();
    expect($alert->dedup_key)->toBe('servers.disk:s1:/:warning')->and($alert->outcome)->toBe(AlertOutcome::Delivered);

    $this->conditions->observe($this->organization->id, 'servers.disk:s1:/:warning', false, $this->alert);
    $this->conditions->observe($this->organization->id, 'servers.disk:s1:/:warning', false, $this->alert);

    $recovery = Alert::query()->where('recovery', true)->sole();
    expect(Alert::query()->count())->toBe(2)
        ->and($recovery->title)->toBe('Disk / on web-1 is 85% full')
        ->and($recovery->severity)->toBe(Severity::Info)
        ->and(Condition::query()->count())->toBe(0)
        ->and(DedupState::query()->sole()->resolved_at)->not->toBeNull()
        ->and(Notification::query()->where('user_id', $this->owner->id)->pluck('title')->all())->toContain('Resolved: Disk / on web-1 is 85% full')
        ->and(Http::recorded(fn (Request $r) => str_contains($r->url(), 'hooks.slack.com'))->count())->toBe(2);

    // Holds again: a new episode.
    $this->conditions->observe($this->organization->id, 'servers.disk:s1:/:warning', true, $this->alert);
    expect(Alert::query()->where('recovery', false)->count())->toBe(2);
});

it('waits until the condition held for the given time', function () {
    $key = 'fleet.outdated:s1';
    $this->conditions->observe($this->organization->id, $key, true, $this->alert, forSeconds: 3600);
    $this->travel(30)->minutes();
    $this->conditions->observe($this->organization->id, $key, true, $this->alert, forSeconds: 3600);
    expect(Alert::query()->count())->toBe(0);

    // Cleared before the hour: nothing to resolve, and the clock restarts.
    $this->conditions->observe($this->organization->id, $key, false, $this->alert, forSeconds: 3600);
    $this->conditions->observe($this->organization->id, $key, true, $this->alert, forSeconds: 3600);
    $this->travel(59)->minutes();
    $this->conditions->observe($this->organization->id, $key, true, $this->alert, forSeconds: 3600);
    expect(Alert::query()->count())->toBe(0);

    $this->travel(2)->minutes();
    $this->conditions->observe($this->organization->id, $key, true, $this->alert, forSeconds: 3600);
    expect(Alert::query()->count())->toBe(1);
});

it('uses a custom recovery and clears conditions of things that disappeared', function () {
    $this->conditions->observe($this->organization->id, 'servers.disk:s1:/data:warning', true, $this->alert);
    $this->conditions->observe($this->organization->id, 'servers.disk:s1:/:warning', true, $this->alert);
    $this->conditions->observe($this->organization->id, 'servers.disk:s2:/:warning', true, $this->alert);

    $this->conditions->clearExcept($this->organization->id, 'servers.disk:s1:', ['servers.disk:s1:/:warning']);

    expect(Condition::query()->pluck('key')->sort()->values()->all())->toBe(['servers.disk:s1:/:warning', 'servers.disk:s2:/:warning'])
        ->and(Alert::query()->where('recovery', true)->sole()->dedup_key)->toBe('servers.disk:s1:/data:warning');

    $this->conditions->observe($this->organization->id, 'servers.disk:s2:/:warning', false, $this->alert,
        fn () => new AlertData($this->organization->id, 'servers.disk_usage', Severity::Info, 'Disk / on web-2 has room again'));

    expect(Alert::query()->where('recovery', true)->latest('id')->first()->title)->toBe('Disk / on web-2 has room again');
});

it('keeps conditions of different organizations apart', function () {
    [, $other] = memberOf();
    $this->conditions->observe($this->organization->id, 'servers.disk:s1:/:warning', true, $this->alert);
    $this->conditions->observe($other->id, 'servers.disk:s1:/:warning', false, $this->alert);
    $this->conditions->clearExcept($other->id, 'servers.disk:', []);

    expect(Condition::query()->sole()->organization_id)->toBe($this->organization->id)
        ->and(Alert::query()->where('recovery', true)->count())->toBe(0);
});

it('forgets conditions nobody observed for a week and releases their dedup key', function () {
    $this->conditions->observe($this->organization->id, 'servers.disk:s1:/:warning', true, $this->alert);
    $this->travel(8)->days();

    (new PruneAlerting)->handle();

    expect(Condition::query()->count())->toBe(0)
        ->and(DedupState::query()->sole()->resolved_at)->not->toBeNull()
        ->and(Alert::query()->where('recovery', true)->count())->toBe(0);
});

it('carries the suggested fix: the alert\'s own, else its type\'s, only with a link', function () {
    $alerts = app(Alerts::class);
    $alerts->raise(new AlertData($this->organization->id, 'volumes.almost_full', Severity::Warning, 'Volume data is 90% full', url: '/volumes/v1'));
    $alerts->raise(new AlertData($this->organization->id, 'volumes.almost_full', Severity::Warning, 'Volume logs is 90% full', url: '/volumes/v2', action: 'Prune logs'));
    $alerts->raise(new AlertData($this->organization->id, 'volumes.almost_full', Severity::Warning, 'Volume tmp is 90% full'));

    expect(Alert::query()->orderBy('id')->pluck('action')->all())->toBe(['Grow volume', 'Prune logs', null])
        ->and(Notification::query()->where('user_id', $this->owner->id)->whereNotNull('action')->count())->toBe(2);

    $message = AlertMessage::fromAlert(Alert::query()->orderBy('id')->first());
    expect($message->linkLabel())->toBe('Grow volume')->and($message->toPayload()['action'])->toBe('Grow volume');

    $slack = Http::recorded(fn (Request $r) => str_contains($r->url(), 'hooks.slack.com'))->first()[0];
    expect(json_encode($slack->data()))->toContain('Suggested fix: Grow volume');
});
