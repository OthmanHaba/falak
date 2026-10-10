<?php

use Falak\Alerting\Domain\Models\Alert;
use Falak\Identity\Contracts\Role;
use Falak\Secrets\Application\Actions\RevealSecret;
use Falak\Secrets\Application\Actions\SetSecretValue;
use Falak\Secrets\Application\Jobs\CheckSecretRotation;
use Falak\Secrets\Contracts\Data\SecretAccessor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    Carbon::setTestNow('2026-10-10 12:00:00');
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
});

afterEach(fn () => Carbon::setTestNow());

function secret_alerts(string $type, bool $recovery = false): Collection
{
    return Alert::query()->where('type', $type)->where('recovery', $recovery)->get();
}

it('alerts when a secret outlives its rotation policy and resolves once rotated', function () {
    $secret = secrets_create($this->organization, 'STRIPE_KEY', 'sk_live_1', attributes: ['rotation_days' => 30]);
    secrets_create($this->organization, 'NO_POLICY', 'x');

    Carbon::setTestNow(now()->addDays(29));
    dispatch_sync(new CheckSecretRotation);
    expect(secret_alerts('secrets.rotation_due'))->toHaveCount(0);

    Carbon::setTestNow(now()->addDays(2));
    dispatch_sync(new CheckSecretRotation);
    dispatch_sync(new CheckSecretRotation);
    $alert = secret_alerts('secrets.rotation_due')->sole();
    expect($alert->title)->toBe('Secret STRIPE_KEY is due for rotation')
        ->and($alert->url)->toBe(url('/settings/secrets'))
        ->and($alert->action)->toBe('Rotate secret')
        ->and($alert->body)->not->toContain('sk_live_1');

    app(SetSecretValue::class)($secret->refresh(), 'sk_live_2', $this->user->id);
    dispatch_sync(new CheckSecretRotation);
    expect(secret_alerts('secrets.rotation_due', recovery: true)->sole()->title)->toBe('Secret STRIPE_KEY was rotated');
});

it('resolves a rotation alert when the policy is removed', function () {
    $secret = secrets_create($this->organization, 'TOKEN', 'v', attributes: ['rotation_days' => 1]);
    Carbon::setTestNow(now()->addDays(2));
    dispatch_sync(new CheckSecretRotation);

    $secret->forceFill(['rotation_days' => null])->save();
    dispatch_sync(new CheckSecretRotation);

    expect(secret_alerts('secrets.rotation_due', recovery: true))->toHaveCount(1);
});

it('alerts once when one user reveals more than the limit within ten minutes', function () {
    config(['secrets.reveal_alert.count' => 3]);
    $secrets = collect(range(1, 6))->map(fn ($i) => secrets_create($this->organization, "KEY_{$i}", "value-{$i}", attributes: ['sensitive' => false]));
    $reveal = fn ($secret, string $userId) => app(RevealSecret::class)($secret, null, SecretAccessor::user($userId, 'Revealed in the dashboard', '198.51.100.7'));

    foreach ($secrets->take(3) as $secret) {
        $reveal($secret, $this->user->id);
    }
    [$other] = memberOf($this->organization, Role::Admin);
    $reveal($secrets[3], $other->id); // another user's reveals count apart
    expect(secret_alerts('secrets.unusual_reveals'))->toHaveCount(0);

    $reveal($secrets[3], $this->user->id);
    $reveal($secrets[4], $this->user->id);
    $alert = secret_alerts('secrets.unusual_reveals')->sole();
    expect($alert->title)->toBe("{$this->user->email} revealed more than 3 secrets in 10 minutes")
        ->and($alert->body)->toContain('KEY_4')->and($alert->body)->not->toContain('value-4')
        ->and($alert->organization_id)->toBe($this->organization->id);

    // A later burst alerts again.
    Carbon::setTestNow(now()->addMinutes(11));
    foreach ($secrets->take(4) as $secret) {
        $reveal($secret, $this->user->id);
    }
    expect(secret_alerts('secrets.unusual_reveals'))->toHaveCount(2);
});
