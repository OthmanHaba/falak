<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\SourceControl\Contracts\ProviderType;
use Falak\SourceControl\Contracts\SourceControlGateway;
use Falak\SourceControl\Domain\Models\Connection;
use Falak\SourceControl\Domain\Models\DeployKey;
use Falak\SourceControl\Domain\Models\Push;
use Falak\SourceControl\Events\ConnectionDeleted;

require_once __DIR__.'/../Support/helpers.php';

it('lists connections and recent pushes', function () {
    [, $organization] = actingAsMember(Role::Viewer);
    $connection = sc_connection($organization->id);
    sc_connection(memberOf()[1]->id); // other organization
    Push::query()->create(['organization_id' => $organization->id, 'connection_id' => $connection->id, 'repository' => 'acme/shop', 'branch' => 'main', 'sha' => str_repeat('a', 40), 'message' => "Fix\nbody", 'received_at' => now()]);

    $this->get('/settings/source-control')->assertOk()->assertInertia(fn ($page) => $page->component('SourceControl/Index', false)
        ->has('connections', 1)
        ->where('connections.0.provider', 'github')
        ->missing('connections.0.credentials')
        ->has('pushes', 1)
        ->where('pushes.0.message', 'Fix')
        ->where('canManage', false)
        ->has('providers', 4));
});

it('connects a GitHub personal access token after verifying it', function () {
    [, $organization] = actingAsMember(Role::Admin);
    Http::fake(['api.github.com/user' => Http::response(['login' => 'ada'])]);

    $this->post('/source-control/connections', ['provider' => 'github', 'auth_type' => 'token', 'token' => 'ghp_secret'])
        ->assertRedirect('/settings/source-control')->assertSessionHasNoErrors();

    $connection = Connection::query()->sole();
    expect($connection->name)->toBe('GitHub (ada)')
        ->and($connection->account)->toBe('ada')
        ->and($connection->organization_id)->toBe($organization->id)
        ->and($connection->credential('token'))->toBe('ghp_secret')
        ->and($connection->getRawOriginal('credentials'))->not->toContain('ghp_secret');

    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer ghp_secret'));
});

it('rejects credentials the provider refuses', function () {
    actingAsMember(Role::Admin);
    Http::fake(['gitlab.example.com/*' => Http::response(['message' => '401 Unauthorized'], 401)]);

    $this->post('/source-control/connections', ['provider' => 'gitlab', 'auth_type' => 'token', 'token' => 'bad', 'base_url' => 'https://gitlab.example.com'])
        ->assertSessionHasErrors('credentials');

    expect(Connection::query()->count())->toBe(0);
});

it('connects Bitbucket app passwords and custom git', function () {
    actingAsMember(Role::Admin);
    Http::fake(['api.bitbucket.org/2.0/user' => Http::response(['username' => 'ada'])]);

    $this->post('/source-control/connections', ['provider' => 'bitbucket', 'auth_type' => 'basic', 'username' => 'ada', 'password' => 'app-pass'])->assertSessionHasNoErrors();
    $this->post('/source-control/connections', ['provider' => 'custom', 'auth_type' => 'none', 'name' => 'Internal git', 'base_url' => 'https://git.acme.test'])->assertSessionHasNoErrors();
    $this->post('/source-control/connections', ['provider' => 'custom', 'auth_type' => 'token', 'token' => 'x'])->assertSessionHasErrors('auth_type');
    $this->post('/source-control/connections', ['provider' => 'github', 'auth_type' => 'token'])->assertSessionHasErrors('token');

    expect(Connection::query()->orderBy('name')->pluck('name')->all())->toBe(['Bitbucket (ada)', 'Internal git']);
});

it('forbids viewers and developers from managing connections', function (Role $role) {
    [, $organization] = actingAsMember($role);
    $connection = sc_connection($organization->id);

    $this->post('/source-control/connections', ['provider' => 'custom', 'auth_type' => 'none'])->assertForbidden();
    $this->delete("/source-control/connections/{$connection->id}", ['name' => $connection->name])->assertForbidden();
    $this->get('/source-control/connect/github')->assertForbidden();
})->with([Role::Viewer, Role::Developer]);

it('hides other organizations connections', function () {
    actingAsMember(Role::Owner);
    $foreign = sc_connection(memberOf()[1]->id);

    $this->delete("/source-control/connections/{$foreign->id}", ['name' => $foreign->name])->assertNotFound();
    $this->getJson("/source-control/connections/{$foreign->id}/repositories")->assertNotFound();
    $this->getJson("/source-control/connections/{$foreign->id}/branches?repository=acme/shop")->assertNotFound();
});

it('disconnects after confirmation, cleaning up the provider', function () {
    Event::fake([ConnectionDeleted::class]);
    [, $organization] = actingAsMember(Role::Admin);
    Http::fake([
        'api.github.com/repos/acme/shop/keys' => Http::response(['id' => 3], 201),
        'api.github.com/repos/acme/shop/hooks' => Http::response(['id' => 4], 201),
        'api.github.com/*' => Http::response(null, 204),
    ]);
    $connection = sc_connection($organization->id);
    app(SourceControlGateway::class)->installDeployKey($connection->id, 'acme/shop', 'Falak');
    app(SourceControlGateway::class)->ensureWebhook($connection->id, 'acme/shop');

    $this->delete("/source-control/connections/{$connection->id}", ['name' => 'wrong'])->assertSessionHasErrors('name');
    $this->delete("/source-control/connections/{$connection->id}", ['name' => $connection->name])->assertRedirect('/settings/source-control');

    expect(Connection::query()->count())->toBe(0)->and(DeployKey::query()->count())->toBe(0);
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/keys/3'));
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/hooks/4'));
    Event::assertDispatched(ConnectionDeleted::class, fn ($e) => $e->connectionId === $connection->id && $e->provider === 'github');
});

it('serves repositories and branches as JSON for the site wizard', function () {
    [, $organization] = actingAsMember(Role::Developer);
    $connection = sc_connection($organization->id);
    Http::fake([
        'api.github.com/user/repos*' => Http::response([['full_name' => 'acme/shop', 'default_branch' => 'main', 'private' => true, 'ssh_url' => 'git@github.com:acme/shop.git', 'clone_url' => 'https://github.com/acme/shop.git', 'html_url' => 'https://github.com/acme/shop']]),
        'api.github.com/repos/acme/shop/branches*' => Http::response([['name' => 'main', 'commit' => ['sha' => 'abc'], 'protected' => true]]),
    ]);

    $this->getJson("/source-control/connections/{$connection->id}/repositories?search=shop")->assertOk()->assertExactJson(['data' => [[
        'full_name' => 'acme/shop', 'default_branch' => 'main', 'private' => true, 'ssh_url' => 'git@github.com:acme/shop.git', 'https_url' => 'https://github.com/acme/shop.git', 'web_url' => 'https://github.com/acme/shop',
    ]]]);
    $this->getJson("/source-control/connections/{$connection->id}/branches?repository=acme/shop")->assertOk()->assertExactJson(['data' => [['name' => 'main', 'sha' => 'abc', 'protected' => true]]]);
    $this->getJson("/source-control/connections/{$connection->id}/branches")->assertUnprocessable()->assertJsonValidationErrors('repository');
});

it('reports provider errors as 422 JSON', function () {
    [, $organization] = actingAsMember(Role::Developer);
    $connection = sc_connection($organization->id);
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

    $this->getJson("/source-control/connections/{$connection->id}/repositories")->assertUnprocessable()->assertJson(['message' => 'GitHub: Authentication failed; reconnect the account. (HTTP 401)']);
});

it('purges connections when the organization is deleted', function () {
    [, $organization] = memberOf();
    $other = memberOf()[1];
    Http::fake();
    $connection = sc_connection($organization->id, ProviderType::Custom, 'none', []);
    sc_connection($other->id, ProviderType::Custom, 'none', []);
    Push::query()->create(['organization_id' => $organization->id, 'connection_id' => $connection->id, 'repository' => 'r', 'branch' => 'main', 'sha' => 'a', 'message' => 'm', 'received_at' => now()]);

    OrganizationDeleted::dispatch($organization->id);

    expect(Connection::query()->pluck('organization_id')->all())->toBe([$other->id])
        ->and(Push::query()->count())->toBe(0);
});
