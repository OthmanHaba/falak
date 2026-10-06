<?php

use Falak\Deployments\Contracts\DeploymentTrigger;
use Falak\Identity\Domain\Models\Organization;
use Falak\Kernel\Security\DataKey;
use Falak\Kernel\Security\KeyRing;
use Falak\Processes\Contracts\ProcessControl;
use Falak\Secrets\Application\Actions\RollBackSecret;
use Falak\Secrets\Application\Actions\SetSecretValue;
use Falak\Secrets\Application\Jobs\PollLinkedSecrets;
use Falak\Secrets\Application\LinkedSecretWatch;
use Falak\Secrets\Application\Providers\ValueFingerprint;
use Falak\Secrets\Contracts\Data\ScopeChain;
use Falak\Secrets\Contracts\Secrets;
use Falak\Secrets\Domain\Enums\OnChange;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Secrets\Domain\Models\SecretVersion;
use Falak\Secrets\Events\LinkedSecretChanged;
use Falak\Secrets\Events\ProviderUnreachable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = memberOf();
    $this->environment = projects_default_env($this->organization);
    $this->site = projects_site($this->organization, 'web', ['DB_PASSWORD' => '${{ secrets.DB_PASS }}'], $this->environment);
    $this->chain = new ScopeChain($this->organization->id, $this->environment->project_id, $this->environment->id, secrets_service_of($this->site));

    secrets_guard();
    Http::preventStrayRequests();
    $GLOBALS['upstream'] = 'first';
    Http::fake(fn () => $GLOBALS['upstream'] === null
        ? Http::response(['errors' => []], 503)
        : Http::response(['data' => ['data' => ['DB_PASS' => $GLOBALS['upstream']]]]));

    $this->deployments = new class implements DeploymentTrigger
    {
        /** @var list<array{site: string, message: ?string}> */
        public array $deployed = [];

        public function deploy(string $siteId, ?string $requestedBy = null, ?string $commit = null, ?string $message = null, ?string $author = null): string
        {
            $this->deployed[] = ['site' => $siteId, 'message' => $message];

            return '01k6deploy0000000000000000';
        }
    };
    $this->processes = new class implements ProcessControl
    {
        /** @var list<array{site: string, new_release: bool}> */
        public array $restarts = [];

        public function restartForSite(string $siteId, ?string $serverId = null, bool $newRelease = true): array
        {
            $this->restarts[] = ['site' => $siteId, 'new_release' => $newRelease];

            return [];
        }

        public function converge(string ...$serverIds): void {}
    };
    app()->instance(DeploymentTrigger::class, $this->deployments);
    app()->instance(ProcessControl::class, $this->processes);

    $this->provider = secrets_vault($this->organization, attributes: ['cache_ttl_seconds' => 3600]);
});

function secrets_watched(Organization $organization, OnChange $onChange, int $minutes = 5): Secret
{
    return secrets_linked($organization, 'DB_PASS', 'vault://kv/data/app#DB_PASS', test()->provider, ['watch_minutes' => $minutes, 'on_change' => $onChange->value]);
}

it('records a baseline first, then a new version with the value as a snapshot when it changes upstream', function () {
    Event::fake([LinkedSecretChanged::class]);
    $secret = secrets_watched($this->organization, OnChange::None);
    $watch = app(LinkedSecretWatch::class);

    expect($watch->poll($secret))->toBeFalse()
        ->and($secret->refresh()->current_version)->toBe(1)
        ->and($secret->value_hmac)->toStartWith(app(KeyRing::class)->organization($this->organization->id)->id.':')
        // An HMAC under the organization's key, not a plain hash anyone could check a guess against.
        ->and($secret->value_hmac)->not->toContain(hash('sha256', 'first'))
        ->and($secret->next_poll_at->isFuture())->toBeTrue()
        ->and(SecretVersion::query()->where('secret_id', $secret->id)->sole()->snapshot)->toStartWith('fk1:');

    expect($watch->poll($secret->refresh()))->toBeFalse()->and($secret->refresh()->current_version)->toBe(1);

    $GLOBALS['upstream'] = 'second';
    expect($watch->poll($secret->refresh()))->toBeTrue();

    $version = SecretVersion::query()->where('secret_id', $secret->id)->where('version', 2)->sole();
    expect($secret->refresh()->current_version)->toBe(2)
        ->and($version->note)->toBe('Changed upstream')
        ->and($version->snapshot)->not->toContain('second')
        ->and($version->pinned())->toBeFalse()
        ->and(app(ValueFingerprint::class)->matches($this->organization->id, $secret->value_hmac, 'second'))->toBeTrue()
        ->and(DB::table('identity_audit_log')->where('action', 'secret.changed_upstream')->value('payload'))->not->toContain('second');

    Event::assertDispatched(LinkedSecretChanged::class, fn (LinkedSecretChanged $e) => $e->name === 'DB_PASS' && $e->version === 2 && $e->siteIds === []
        && ! str_contains(json_encode($e->toAlert()), 'second'));
    expect($this->deployments->deployed)->toBe([])->and($this->processes->restarts)->toBe([]);
});

it('redeploys or restarts the services that use a changed secret', function (OnChange $onChange) {
    $secret = secrets_watched($this->organization, $onChange);
    $watch = app(LinkedSecretWatch::class);
    $watch->poll($secret);

    $GLOBALS['upstream'] = 'rotated';
    $watch->poll($secret->refresh());

    if ($onChange === OnChange::Redeploy) {
        expect($this->deployments->deployed)->toBe([['site' => $this->site->id, 'message' => 'Secret DB_PASS changed upstream']])
            ->and($this->processes->restarts)->toBe([]);
    } else {
        expect($this->processes->restarts)->toBe([['site' => $this->site->id, 'new_release' => false]])
            ->and($this->deployments->deployed)->toBe([]);
    }
})->with([OnChange::Redeploy, OnChange::Restart]);

it('pins a secret rolled back to a recorded value until a new reference is saved', function () {
    $secret = secrets_watched($this->organization, OnChange::None);
    $watch = app(LinkedSecretWatch::class);
    $watch->poll($secret);
    $GLOBALS['upstream'] = 'bad-rotation';
    $watch->poll($secret->refresh());

    // Roll back to v1 (the value seen before the bad rotation): deployments use it, the provider is not asked.
    $restored = app(RollBackSecret::class)($secret->refresh(), 1, $this->user->id);
    expect($restored->pinned())->toBeTrue()->and($restored->note)->toBe('Pinned to the value of v1');

    Http::assertSentCount(2);
    expect(app(Secrets::class)->resolve($this->chain, ['DB_PASS'])->values['DB_PASS'])->toBe('first')
        ->and($watch->poll($secret->refresh()))->toBeFalse()
        ->and($secret->refresh()->current_version)->toBe(3);
    Http::assertSentCount(2); // not asked while pinned

    // A new reference unpins it and restarts change detection.
    $GLOBALS['upstream'] = 'fixed';
    app(SetSecretValue::class)($secret->refresh(), 'vault://kv/data/app#DB_PASS', $this->user->id);
    $this->travel(3601)->seconds(); // past the provider's cache TTL
    expect($secret->refresh()->value_hmac)->toBeNull()
        ->and(app(Secrets::class)->resolve($this->chain, ['DB_PASS'])->values['DB_PASS'])->toBe('fixed');
});

it('alerts and keeps the version when the provider fails during a poll', function () {
    Event::fake([ProviderUnreachable::class, LinkedSecretChanged::class]);
    $secret = secrets_watched($this->organization, OnChange::Redeploy);
    app(LinkedSecretWatch::class)->poll($secret);

    $GLOBALS['upstream'] = null;
    expect(app(LinkedSecretWatch::class)->poll($secret->refresh()))->toBeFalse()
        ->and($secret->refresh()->current_version)->toBe(1);

    Event::assertDispatched(ProviderUnreachable::class);
    Event::assertNotDispatched(LinkedSecretChanged::class);
    expect($this->deployments->deployed)->toBe([]);
});

it('still recognizes the last value after the organization data key rotates', function () {
    $secret = secrets_watched($this->organization, OnChange::None);
    app(LinkedSecretWatch::class)->poll($secret);
    $before = $secret->refresh()->value_hmac;

    app(KeyRing::class)->rotate(DataKey::organization($this->organization->id));

    expect(app(LinkedSecretWatch::class)->poll($secret->refresh()))->toBeFalse()
        ->and($secret->refresh()->value_hmac)->toBe($before);
});

it('polls only the watched secrets that are due', function () {
    $due = secrets_watched($this->organization, OnChange::None);
    $unwatched = secrets_linked($this->organization, 'OTHER', 'vault://kv/data/app#DB_PASS', $this->provider);
    $later = secrets_linked($this->organization, 'LATER', 'vault://kv/data/app#DB_PASS', $this->provider, ['watch_minutes' => 60]);
    $later->forceFill(['next_poll_at' => now()->addMinutes(30)])->save();

    app(PollLinkedSecrets::class)->handle(app(LinkedSecretWatch::class));

    expect($due->refresh()->last_polled_at)->not->toBeNull()
        ->and($unwatched->refresh()->last_polled_at)->toBeNull()
        ->and($later->refresh()->last_polled_at)->toBeNull();
});

it('sets the watch over HTTP, at most once a minute, for linked secrets', function () {
    $this->actingAs($this->user);
    $secret = secrets_linked($this->organization, 'DB_PASS', 'vault://kv/data/app#DB_PASS', $this->provider);

    $this->patchJson("/secrets/{$secret->id}", ['watch_minutes' => 0])->assertJsonValidationErrors('watch_minutes');
    $this->patchJson("/secrets/{$secret->id}", ['watch_minutes' => 10, 'on_change' => 'redeploy'])->assertOk()
        ->assertJsonPath('data.watch_minutes', 10)->assertJsonPath('data.on_change', 'redeploy');
    expect($secret->refresh()->next_poll_at)->not->toBeNull();

    $this->patchJson("/secrets/{$secret->id}", ['watch_minutes' => null])->assertOk();
    expect($secret->refresh()->next_poll_at)->toBeNull();

    $page = $this->get('/settings/secrets')->assertOk();
    expect($page->getContent())->not->toContain('value_hmac')->not->toContain('hvs.ROOT-TOKEN');
});
