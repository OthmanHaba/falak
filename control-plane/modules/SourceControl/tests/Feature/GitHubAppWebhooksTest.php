<?php

use Falak\Identity\Contracts\Role;
use Falak\SourceControl\Domain\Models\Push;
use Falak\SourceControl\Domain\Models\Webhook;
use Falak\SourceControl\Events\PushReceived;
use Falak\SourceControl\Infrastructure\Providers\GitHubClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [, $this->organization] = memberOf(null, Role::Owner);
    $this->githubApp = sc_github_app($this->organization->id);
    $this->connection = sc_app_connection($this->organization->id, $this->githubApp);
    Event::fake([PushReceived::class]);
});

function app_push(string $repository = 'acme/shop', string $installation = '555'): array
{
    return sc_github_push(overrides: ['repository' => ['full_name' => $repository], 'installation' => ['id' => (int) $installation]]);
}

it('verifies the signature with the app webhook secret', function () {
    sc_post_app_webhook($this->githubApp->id, 'push', app_push(), 'wrong-secret')->assertStatus(401);
    sc_post_app_webhook('01jnotanappnotanappnotanap', 'push', app_push())->assertNotFound();

    Event::assertNotDispatched(PushReceived::class);
});

it('acknowledges pings', function () {
    sc_post_app_webhook($this->githubApp->id, 'ping', ['zen' => 'Keep it logically awesome.'])->assertOk()->assertJson(['ok' => true]);

    expect($this->githubApp->refresh()->last_delivery_at)->not->toBeNull();
});

it('turns pushes to deployed repositories into PushReceived', function () {
    Webhook::query()->create(['organization_id' => $this->organization->id, 'connection_id' => $this->connection->id, 'repository' => 'acme/shop', 'secret' => 'unused', 'installed' => true]);

    sc_post_app_webhook($this->githubApp->id, 'push', app_push('ACME/Shop'))->assertStatus(202)->assertJson(['received' => 1]);

    Event::assertDispatched(PushReceived::class, fn (PushReceived $e) => $e->organizationId === $this->organization->id
        && $e->connectionId === $this->connection->id
        && $e->provider === 'github'
        && $e->repository === 'acme/shop'
        && $e->branch === 'main'
        && $e->commit->sha === str_repeat('b', 40));
    expect(Push::query()->sole()->webhook_id)->not->toBeNull();
});

it('ignores repositories no site deploys from, other installations and suspended installations', function () {
    Webhook::query()->create(['organization_id' => $this->organization->id, 'connection_id' => $this->connection->id, 'repository' => 'acme/shop', 'secret' => 'unused', 'installed' => true]);

    sc_post_app_webhook($this->githubApp->id, 'push', app_push('acme/blog'))->assertJson(['received' => 0]);
    sc_post_app_webhook($this->githubApp->id, 'push', app_push('acme/shop', '999'))->assertJson(['received' => 0]);

    $this->connection->forceFill(['status' => 'suspended'])->save();
    sc_post_app_webhook($this->githubApp->id, 'push', app_push())->assertJson(['received' => 0]);

    Event::assertNotDispatched(PushReceived::class);
    expect(Push::query()->count())->toBe(0);
});

it('tracks installation suspension and removal', function (string $action, string $status) {
    sc_post_app_webhook($this->githubApp->id, 'installation', ['action' => $action, 'installation' => ['id' => 555]])->assertStatus(202);

    expect($this->connection->refresh()->status)->toBe($status);
})->with([
    'deleted' => ['deleted', 'disconnected'],
    'suspend' => ['suspend', 'suspended'],
]);

it('reactivates unsuspended installations', function () {
    $this->connection->forceFill(['status' => 'suspended'])->save();

    sc_post_app_webhook($this->githubApp->id, 'installation', ['action' => 'unsuspend', 'installation' => ['id' => 555]]);

    expect($this->connection->refresh()->status)->toBe('active');
});

it('refreshes the repository list when access changes', function () {
    Http::fake([
        'api.github.com/app/installations/555/access_tokens' => Http::response(['token' => 'ghs_x'], 201),
        'api.github.com/installation/repositories*' => Http::response(['repositories' => [['full_name' => 'acme/shop'], ['full_name' => 'acme/new']]]),
    ]);

    sc_post_app_webhook($this->githubApp->id, 'installation_repositories', [
        'action' => 'added', 'installation' => ['id' => 555], 'repositories_added' => [['full_name' => 'acme/new']],
    ])->assertStatus(202)->assertJson(['refreshed' => 1]);

    expect(GitHubClient::knownRepositoryCount($this->connection->id))->toBe(2);
});

it('accepts the env app webhook with GITHUB_APP_WEBHOOK_SECRET', function () {
    config(['source_control.github.app' => ['id' => '7', 'slug' => 'ops', 'private_key' => sc_rsa_pem(), 'webhook_secret' => 'env-secret']]);
    $connection = sc_app_connection($this->organization->id, 'env', '777');
    Webhook::query()->create(['organization_id' => $this->organization->id, 'connection_id' => $connection->id, 'repository' => 'acme/shop', 'secret' => 'unused', 'installed' => true]);

    sc_post_app_webhook('env', 'push', app_push('acme/shop', '777'), 'env-secret')->assertStatus(202)->assertJson(['received' => 1]);

    config(['source_control.github.app.webhook_secret' => null]);
    sc_post_app_webhook('env', 'push', app_push('acme/shop', '777'), 'env-secret')->assertNotFound();
});

it('rate limits deliveries per app', function () {
    config(['source_control.github_app_webhook_rate_limit' => 2]);

    sc_post_app_webhook($this->githubApp->id, 'ping', [])->assertOk();
    sc_post_app_webhook($this->githubApp->id, 'ping', [])->assertOk();
    sc_post_app_webhook($this->githubApp->id, 'ping', [])->assertStatus(429);
});
