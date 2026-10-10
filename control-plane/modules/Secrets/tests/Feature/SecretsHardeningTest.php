<?php

use Falak\Identity\Contracts\Role;
use Falak\Secrets\Contracts\Data\ScopeChain;
use Falak\Secrets\Contracts\Data\SecretAccessor;
use Falak\Secrets\Contracts\Secrets;
use Falak\Secrets\Domain\Models\Secret;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember();
    $this->projectId = projects_default_env($this->organization)->project_id;
});

it('never flashes secret values or passwords into the session', function () {
    $this->post('/secrets', ['name' => 'bad name', 'scope' => 'organization', 'scope_id' => $this->organization->id, 'value' => 'sk_FLASHED'])
        ->assertSessionHasErrors('name');
    $this->post('/secrets/reauthenticate', ['password' => 'wrong-PASSWORD', 'code' => '987654'])->assertSessionHasErrors('password');

    expect(json_encode(session()->all()))->not->toContain('sk_FLASHED')->not->toContain('wrong-PASSWORD')->not->toContain('987654');
});

it('throttles re-authentication per user, whatever the address', function () {
    foreach (range(1, 5) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])->postJson('/secrets/reauthenticate', ['password' => 'wrong'])->assertJsonValidationErrors('password');
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])->postJson('/secrets/reauthenticate', ['password' => 'password']);

    $response->assertJsonValidationErrors('password');
    expect($response->json('errors.password.0'))->toContain('Too many');
});

it('accepts only providers of the organization for linked secrets', function () {
    secrets_guard();
    [, $stranger] = memberOf();
    $foreign = secrets_vault($stranger);

    $this->postJson('/secrets', ['name' => 'VAULTED', 'scope' => 'organization', 'scope_id' => $this->organization->id, 'kind' => 'linked', 'reference' => 'vault://kv/data/app#X', 'provider_id' => '01k6provider00000000000000'])
        ->assertJsonValidationErrors('provider_id');
    $this->postJson('/secrets', ['name' => 'VAULTED', 'scope' => 'organization', 'scope_id' => $this->organization->id, 'kind' => 'linked', 'reference' => 'vault://kv/data/app#X', 'provider_id' => $foreign->id])
        ->assertJsonValidationErrors('provider_id');

    $secret = secrets_linked($this->organization, 'VAULTED', 'vault://kv/data/app#X', $provider = secrets_vault($this->organization));
    $this->patchJson("/secrets/{$secret->id}", ['provider_id' => '01k6provider00000000000000'])->assertJsonValidationErrors('provider_id');
    $this->patchJson("/secrets/{$secret->id}", ['provider_id' => $foreign->id])->assertJsonValidationErrors('provider_id');
    // Null: the organization's only provider of the reference's type.
    $this->patchJson("/secrets/{$secret->id}", ['provider_id' => null])->assertOk()->assertJsonPath('data.provider_id', $provider->id);

    $managed = secrets_create($this->organization, 'PLAIN', 'x');
    $this->patchJson("/secrets/{$managed->id}", ['provider_id' => $provider->id])->assertJsonValidationErrors('provider_id');
});

it('answers a duplicate name that lost a race with a validation error', function () {
    Secret::creating(function (Secret $secret) {
        // Another request creates the same name between the check and the insert.
        if (! Secret::query()->where('name', $secret->name)->exists()) {
            DB::table('secrets_secrets')->insert([
                'id' => strtolower((string) Str::ulid()), 'organization_id' => $secret->organization_id, 'scope_type' => $secret->scope_type->value,
                'scope_id' => $secret->scope_id, 'name' => $secret->name, 'kind' => 'managed', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    $this->postJson('/secrets', ['name' => 'RACE', 'scope' => 'organization', 'scope_id' => $this->organization->id, 'value' => 'x'])
        ->assertJsonValidationErrors('name');
});

it('shows access log addresses and actor ids only to managers', function () {
    $secret = secrets_create($this->organization, 'PUBLIC_ID', 'pk', attributes: ['sensitive' => false]);
    app(Secrets::class)->resolve(new ScopeChain($this->organization->id), ['PUBLIC_ID'], SecretAccessor::user($this->user->id, 'Test', '203.0.113.7'));

    $this->getJson("/secrets/{$secret->id}")->assertJsonPath('data.access_log.0.ip', '203.0.113.7')->assertJsonPath('data.access_log.0.actor_id', $this->user->id);

    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer)->getJson("/secrets/{$secret->id}")
        ->assertJsonPath('data.access_log.0.ip', null)
        ->assertJsonPath('data.access_log.0.actor_id', null)
        ->assertJsonPath('data.access_log.0.reason', 'Test');
});

it('lists secrets in a constant number of queries', function () {
    $staging = projects_environment($this->organization, 'staging');
    projects_site($this->organization, 'web', ['A' => '${{ secrets.S0 }}'], $staging);
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get("/projects/{$this->projectId}/settings/secrets")->assertOk();

        return count(DB::getQueryLog());
    };

    secrets_create($this->organization, 'S0', 'x', attributes: ['rotation_days' => 30]);
    $count(); // warms per-process caches (permissions, data keys)
    $one = $count();

    foreach (range(1, 8) as $i) {
        secrets_create($this->organization, "S{$i}", 'x', attributes: ['rotation_days' => 30]);
    }

    expect($count())->toBe($one);
});
