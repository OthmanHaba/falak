<?php

use Falak\Identity\Application\Actions\ConfirmTwoFactor;
use Falak\Identity\Application\Actions\EnableTwoFactor;
use Falak\Identity\Contracts\Role;
use Falak\Secrets\Contracts\AccessorType;
use Falak\Secrets\Contracts\Data\ScopeChain;
use Falak\Secrets\Contracts\Secrets;
use Falak\Secrets\Contracts\SecretScope;
use Falak\Secrets\Domain\Models\AccessLogEntry;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember();
    $this->environment = projects_default_env($this->organization);
    $this->projectId = $this->environment->project_id;
});

it('lists a project\'s secrets with metadata and usage, never values', function () {
    $web = projects_site($this->organization, 'web', ['STRIPE' => '${{ secrets.STRIPE_KEY }}', 'TOKEN' => '${{ secrets.TOKEN }}'], $this->environment);
    $stripe = secrets_create($this->organization, 'STRIPE_KEY', 'sk_live_TOPSECRET', SecretScope::Project, $this->projectId);
    $orgToken = secrets_create($this->organization, 'TOKEN', 'org-VALUE-1', attributes: ['sensitive' => false]);
    // web's own TOKEN shadows the organization's: the organization's is not "used by" web.
    secrets_create($this->organization, 'TOKEN', 'svc-VALUE-2', SecretScope::Service, secrets_service_of($web));
    [, $stranger] = memberOf();
    secrets_create($stranger, 'FOREIGN', 'x');

    $response = $this->get("/projects/{$this->projectId}/settings/secrets")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Secrets/Index', false)
        ->where('context', 'project')
        ->has('secrets', 3)
        ->where('can.manage', true)
        ->where('can.reveal', true));

    $props = $response->viewData('page')['props'];
    $byName = collect($props['secrets'])->groupBy('name');
    $json = json_encode($props);

    expect($json)->not->toContain('TOPSECRET')->not->toContain('VALUE-1')->not->toContain('VALUE-2')->not->toContain('fk1:')
        ->and($byName['STRIPE_KEY'][0]['used_by'][0]['name'])->toBe('web')
        ->and($byName['STRIPE_KEY'][0]['used_by'][0]['variables'])->toBe(['STRIPE'])
        ->and(collect($byName['TOKEN'])->firstWhere('id', $orgToken->id)['used_by'])->toBe([])
        ->and(collect($byName['TOKEN'])->firstWhere('scope', 'service')['used_by'])->toHaveCount(1)
        ->and($byName->has('FOREIGN'))->toBeFalse();

    $this->getJson("/secrets/{$stripe->id}")->assertOk()
        ->assertJsonPath('data.name', 'STRIPE_KEY')
        ->assertJsonPath('data.versions.0.version', 1)
        ->assertJsonMissingPath('data.versions.0.ciphertext');
    expect($this->getJson("/secrets/{$stripe->id}")->getContent())->not->toContain('TOPSECRET');

    $this->get('/settings/secrets')->assertOk()->assertInertia(fn ($page) => $page->where('context', 'organization')->has('secrets', 1));
});

it('creates, updates, versions, rolls back, disables and deletes over JSON', function () {
    $response = $this->postJson('/secrets', ['name' => 'API_KEY', 'scope' => 'project', 'scope_id' => $this->projectId, 'value' => 'first-SECRET', 'sensitive' => false])
        ->assertCreated()->assertJsonPath('data.name', 'API_KEY')->assertJsonPath('data.current_version', 1);
    expect($response->getContent())->not->toContain('first-SECRET');
    $id = $response->json('data.id');

    $this->postJson('/secrets', ['name' => 'lower', 'scope' => 'project', 'scope_id' => $this->projectId, 'value' => 'x'])->assertJsonValidationErrors('name');
    $this->postJson('/secrets', ['name' => 'API_KEY', 'scope' => 'project', 'scope_id' => $this->projectId, 'value' => 'x'])->assertJsonValidationErrors('name');

    $this->postJson("/secrets/{$id}/versions", ['value' => 'second-SECRET'])->assertCreated()->assertJsonPath('data.version', 2);
    $this->postJson("/secrets/{$id}/versions/1/restore")->assertCreated()->assertJsonPath('data.version', 3);
    $this->postJson("/secrets/{$id}/versions/3/disable")->assertJsonValidationErrors('version');
    $this->postJson("/secrets/{$id}/versions/2/disable")->assertNoContent();

    $chain = new ScopeChain($this->organization->id, $this->projectId);
    expect(app(Secrets::class)->resolve($chain, ['API_KEY'])->values['API_KEY'])->toBe('first-SECRET');

    $this->patchJson("/secrets/{$id}", ['sensitive' => true, 'description' => 'Payments', 'rotation_days' => 90])->assertOk()->assertJsonPath('data.sensitive', true)->assertJsonPath('data.rotation_days', 90);
    $this->patchJson("/secrets/{$id}", ['sensitive' => false])->assertJsonValidationErrors('sensitive');

    $this->deleteJson("/secrets/{$id}")->assertNoContent();
    expect(Secret::query()->count())->toBe(0);

    $actions = DB::table('identity_audit_log')->where('subject_id', $id)->pluck('action')->all();
    expect($actions)->toContain('secret.created', 'secret.value_set', 'secret.rolled_back', 'secret.version_disabled', 'secret.updated', 'secret.deleted')
        ->and(DB::table('identity_audit_log')->where('subject_id', $id)->pluck('context')->implode(' '))->not->toContain('SECRET');
});

it('reveals a non-sensitive value only after re-authentication, and logs it', function () {
    $secret = secrets_create($this->organization, 'PUBLIC_ID', 'pk_live_123', attributes: ['sensitive' => false]);

    $this->postJson("/secrets/{$secret->id}/reveal")->assertStatus(423)->assertJsonPath('requires_code', false);
    $this->postJson('/secrets/reauthenticate', ['password' => 'wrong'])->assertJsonValidationErrors('password');
    $this->postJson('/secrets/reauthenticate', ['password' => 'password'])->assertNoContent();

    $this->postJson("/secrets/{$secret->id}/reveal")->assertOk()->assertJsonPath('data.value', 'pk_live_123')->assertJsonPath('data.version', 1);

    $log = AccessLogEntry::query()->sole();
    expect($log->actor_type)->toBe(AccessorType::User)->and($log->user_id)->toBe($this->user->id)->and($log->reason)->toBe('Revealed in the dashboard')
        ->and(DB::table('identity_audit_log')->where('action', 'secret.revealed')->exists())->toBeTrue();
});

it('never reveals a sensitive (write-only) value', function () {
    $secret = secrets_create($this->organization, 'DB_PASS', 'hunter2', attributes: ['sensitive' => true]);
    $this->withSession(['identity.reauthenticated_at' => time()]);

    $this->postJson("/secrets/{$secret->id}/reveal")->assertForbidden();
    expect(AccessLogEntry::query()->count())->toBe(0);
});

it('asks for the two-factor code when 2FA is enabled', function () {
    app(EnableTwoFactor::class)($this->user);
    $key = Fortify::currentEncrypter()->decrypt($this->user->two_factor_secret);
    app(ConfirmTwoFactor::class)($this->user, app(Google2FA::class)->getCurrentOtp($key));
    $secret = secrets_create($this->organization, 'PUBLIC_ID', 'pk', attributes: ['sensitive' => false]);
    // A plain password confirmation is not enough with 2FA on.
    $this->withSession(['auth.password_confirmed_at' => time()]);

    $this->postJson("/secrets/{$secret->id}/reveal")->assertStatus(423)->assertJsonPath('requires_code', true);
    $this->postJson('/secrets/reauthenticate', ['password' => 'password'])->assertJsonValidationErrors('code');
    // The code that confirmed 2FA a moment ago can't be replayed; let the test use it once more.
    $code = app(Google2FA::class)->getCurrentOtp($key);
    Cache::forget('fortify.2fa_codes.'.md5($code));
    $this->postJson('/secrets/reauthenticate', ['password' => 'password', 'code' => $code])->assertNoContent();
    $this->postJson("/secrets/{$secret->id}/reveal")->assertOk()->assertJsonPath('data.value', 'pk');
});

it('enforces secrets.view, secrets.reveal and secrets.manage', function () {
    $secret = secrets_create($this->organization, 'PUBLIC_ID', 'pk', attributes: ['sensitive' => false]);

    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer)->withSession(['identity.reauthenticated_at' => time()]);
    $this->get("/projects/{$this->projectId}/settings/secrets")->assertOk()->assertInertia(fn ($page) => $page->where('can.manage', false)->where('can.reveal', false));
    $this->getJson("/secrets/{$secret->id}")->assertOk();
    $this->postJson("/secrets/{$secret->id}/reveal")->assertForbidden();
    $this->postJson("/secrets/{$secret->id}/versions", ['value' => 'x'])->assertForbidden();
    $this->postJson('/secrets', ['name' => 'X', 'scope' => 'organization', 'scope_id' => $this->organization->id, 'value' => 'x'])->assertForbidden();
    $this->deleteJson("/secrets/{$secret->id}")->assertForbidden();

    [$developer] = memberOf($this->organization, Role::Developer);
    $this->actingAs($developer);
    $this->postJson("/secrets/{$secret->id}/versions", ['value' => 'y'])->assertCreated();
    $this->postJson("/secrets/{$secret->id}/reveal")->assertOk();

    // Another organization's secret does not exist for this member.
    [, $stranger] = memberOf();
    $foreign = secrets_create($stranger, 'FOREIGN', 'x');
    $this->getJson("/secrets/{$foreign->id}")->assertNotFound();
    $this->postJson('/secrets', ['name' => 'X', 'scope' => 'organization', 'scope_id' => $stranger->id, 'value' => 'x'])->assertJsonValidationErrors('scope_id');
});

it('promotes a site variable to a service secret', function () {
    $web = projects_site($this->organization, 'web', ['STRIPE_KEY' => 'sk_live_PROMOTED', 'DB' => '${{ db.DATABASE_URL }}'], $this->environment);
    $serviceId = secrets_service_of($web);

    $this->getJson("/secrets/promotable?service_id={$serviceId}")->assertOk()->assertJsonPath('data.keys', ['STRIPE_KEY']);
    $this->postJson('/secrets/promote', ['service_id' => $serviceId, 'key' => 'DB', 'name' => 'DB'])->assertJsonValidationErrors('key');

    $this->postJson('/secrets/promote', ['service_id' => $serviceId, 'key' => 'STRIPE_KEY', 'name' => 'STRIPE_KEY'])
        ->assertCreated()->assertJsonPath('data.scope', 'service')->assertJsonPath('data.sensitive', true);

    $environment = app(SiteDirectory::class)->environment($web->id);
    expect($environment->variables['STRIPE_KEY'])->toBe('${{ secrets.STRIPE_KEY }}')
        ->and($environment->version)->toBe(2)
        ->and(DB::table('identity_audit_log')->where('action', 'site.environment_promoted')->exists())->toBeTrue();

    $chain = new ScopeChain($this->organization->id, $this->projectId, $this->environment->id, $serviceId);
    expect(app(Secrets::class)->resolve($chain, ['STRIPE_KEY'])->values['STRIPE_KEY'])->toBe('sk_live_PROMOTED');
});
