<?php

use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\SourceControl\Contracts\Exceptions\ConnectionNotFound;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Contracts\ProviderType;
use Falak\SourceControl\Contracts\SourceControlGateway;
use Falak\SourceControl\Domain\Models\DeployKey;
use Falak\SourceControl\Domain\Models\Webhook;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [, $this->organization] = memberOf(null, Role::Owner);
    $this->gateway = app(SourceControlGateway::class);
});

it('exposes connections without secrets', function () {
    $connection = sc_connection($this->organization->id);
    sc_connection(memberOf()[1]->id);

    $data = $this->gateway->connection($connection->id);

    expect($data->provider)->toBe(ProviderType::GitHub)
        ->and($data->authType)->toBe('token')
        ->and($this->gateway->connections($this->organization->id))->toHaveCount(1)
        ->and(json_encode($connection->toArray()))->not->toContain('tok_123')
        ->and($this->gateway->connection('01JUNKNOWN0000000000000000'))->toBeNull();
});

it('installs a generated deploy key at the provider', function () {
    Http::fake(['api.github.com/repos/acme/shop/keys' => Http::response(['id' => 501], 201)]);
    $connection = sc_connection($this->organization->id);

    $key = $this->gateway->installDeployKey($connection->id, 'acme/shop', 'Falak shop');

    expect($key->installed)->toBeTrue()
        ->and($key->publicKey)->toStartWith('ssh-ed25519 ')
        ->and($key->repository)->toBe('acme/shop');

    $model = DeployKey::query()->findOrFail($key->id);
    expect($model->provider_key_id)->toBe('501')
        ->and($model->getRawOriginal('private_key'))->not->toContain('OPENSSH')
        ->and(AuditEntry::query()->where('action', 'source_control.deploy_key_created')->exists())->toBeTrue();

    Http::assertSent(fn (Request $r) => $r['key'] === $key->publicKey && $r['read_only'] === true);
});

it('keeps the deploy key and reports the error when installation fails', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Resource not accessible'], 403)]);
    $connection = sc_connection($this->organization->id);

    $key = $this->gateway->installDeployKey($connection->id, 'acme/shop', 'Falak shop');

    expect($key->installed)->toBeFalse()
        ->and($key->installError)->toContain('Permission denied');
});

it('does not call any API for custom git deploy keys', function () {
    Http::fake();
    $connection = sc_connection($this->organization->id, ProviderType::Custom, 'none', []);

    $key = $this->gateway->installDeployKey($connection->id, 'git@git.acme.test:acme/shop.git', 'Falak');

    expect($key->installed)->toBeFalse()->and($key->installError)->toBeNull();
    Http::assertNothingSent();
});

it('hands out SSH credentials for deploy keys with known hosts', function () {
    Http::fake(['api.github.com/*' => Http::response(['id' => 1], 201)]);
    $connection = sc_connection($this->organization->id);
    $key = $this->gateway->installDeployKey($connection->id, 'acme/shop', 'Falak');

    $credentials = $this->gateway->checkoutCredentials($connection->id, 'acme/shop', $key->id);

    expect($credentials->url)->toBe('git@github.com:acme/shop.git')
        ->and($credentials->usesSsh())->toBeTrue()
        ->and($credentials->sshPrivateKey)->toStartWith('-----BEGIN OPENSSH PRIVATE KEY-----')
        ->and($credentials->knownHosts)->toStartWith('github.com ssh-ed25519 ')
        ->and($this->gateway->cloneUrl($connection->id, 'acme/shop'))->toBe('git@github.com:acme/shop.git');
});

it('hands out HTTPS token credentials without a deploy key', function () {
    $connection = sc_connection($this->organization->id, ProviderType::GitLab, 'token', ['token' => 'glpat-1']);

    $credentials = $this->gateway->checkoutCredentials($connection->id, 'acme/shop');

    expect($credentials->url)->toBe('https://gitlab.com/acme/shop.git')
        ->and($credentials->usesSsh())->toBeFalse()
        ->and($credentials->httpsUsername)->toBe('oauth2')
        ->and($credentials->httpsPassword)->toBe('glpat-1');
});

it('refuses deploy keys of another connection', function () {
    Http::fake(['*' => Http::response(['id' => 1], 201)]);
    $a = sc_connection($this->organization->id);
    $b = sc_connection($this->organization->id);
    $key = $this->gateway->installDeployKey($a->id, 'acme/shop', 'Falak');

    $this->gateway->checkoutCredentials($b->id, 'acme/shop', $key->id);
})->throws(SourceControlException::class);

it('removes deploy keys at the provider', function () {
    Http::fake([
        'api.github.com/repos/acme/shop/keys' => Http::response(['id' => 9], 201),
        'api.github.com/repos/acme/shop/keys/9' => Http::response(null, 204),
    ]);
    $key = $this->gateway->installDeployKey(sc_connection($this->organization->id)->id, 'acme/shop', 'Falak');

    $this->gateway->removeDeployKey($key->id);

    expect($this->gateway->deployKey($key->id))->toBeNull();
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/keys/9'));
});

it('ensures webhooks idempotently and removes them', function () {
    config(['source_control.webhook_url' => 'https://hooks.falak.test']);
    Http::fake([
        'api.github.com/repos/acme/shop/hooks' => Http::response(['id' => 314], 201),
        'api.github.com/repos/acme/shop/hooks/314' => Http::response(null, 204),
    ]);
    $connection = sc_connection($this->organization->id);

    $first = $this->gateway->ensureWebhook($connection->id, 'acme/shop');
    $second = $this->gateway->ensureWebhook($connection->id, 'acme/shop');

    expect($first->installed)->toBeTrue()
        ->and($first->url)->toBe("https://hooks.falak.test/api/webhooks/source-control/{$first->id}")
        ->and($second->id)->toBe($first->id);
    Http::assertSentCount(1);

    $secret = Webhook::query()->findOrFail($first->id)->secret;
    Http::assertSent(fn (Request $r) => ($r['config']['secret'] ?? null) === $secret);

    $this->gateway->removeWebhook($connection->id, 'acme/shop');
    expect(Webhook::query()->count())->toBe(0);
});

it('returns a manual webhook for custom git', function () {
    Http::fake();
    $connection = sc_connection($this->organization->id, ProviderType::Custom, 'none', []);

    $webhook = $this->gateway->ensureWebhook($connection->id, 'git@git.acme.test:acme/shop.git');

    expect($webhook->installed)->toBeFalse()->and($webhook->url)->toContain('/api/webhooks/source-control/');
    Http::assertNothingSent();
});

it('throws for unknown connections', function () {
    $this->gateway->branches('01JUNKNOWN0000000000000000', 'acme/shop');
})->throws(ConnectionNotFound::class);
