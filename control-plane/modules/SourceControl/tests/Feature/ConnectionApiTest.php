<?php

use Falak\Identity\Contracts\Role;
use Falak\SourceControl\Domain\Models\Connection;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
});

it('creates a custom git connection and never returns credentials', function () {
    $this->postJson('/api/v1/source-control/connections', [
        'provider' => 'custom', 'auth_type' => 'none', 'name' => 'internal git',
    ])->assertCreated()->assertJsonPath('data.provider', 'custom')->assertJsonMissingPath('data.credentials');

    $this->getJson('/api/v1/source-control/connections')->assertOk()->assertJsonPath('data.0.name', 'internal git');
});

it('verifies and stores token credentials encrypted and write-only', function () {
    Http::preventStrayRequests();
    Http::fake(['api.github.com/user' => Http::response(['login' => 'octocat'])]);

    $this->postJson('/api/v1/source-control/connections', [
        'provider' => 'github', 'auth_type' => 'token', 'token' => 'ghp_secret',
    ])->assertCreated()->assertJsonPath('data.name', 'GitHub (octocat)')->assertJsonMissingPath('data.token');

    expect(json_encode(Connection::query()->firstOrFail()->getAttributes()))->not->toContain('ghp_secret');
});

it('enforces the auth types each provider accepts', function () {
    $this->postJson('/api/v1/source-control/connections', ['provider' => 'custom', 'auth_type' => 'token', 'token' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors('auth_type');
    $this->postJson('/api/v1/source-control/connections', ['provider' => 'bitbucket', 'auth_type' => 'token', 'token' => 'x', 'base_url' => 'https://bb.example.com'])
        ->assertUnprocessable()->assertJsonValidationErrors('base_url');
});

it('requires source_control.manage to connect', function () {
    [$developer] = memberOf($this->organization, Role::Developer);

    $this->actingAs($developer)->postJson('/api/v1/source-control/connections', ['provider' => 'custom', 'auth_type' => 'none'])->assertForbidden();
});
