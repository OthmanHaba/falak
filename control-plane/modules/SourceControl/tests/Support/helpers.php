<?php

use Illuminate\Testing\TestResponse;
use Falak\SourceControl\Contracts\ProviderType;
use Falak\SourceControl\Domain\Models\Connection;
use Falak\SourceControl\Domain\Models\GitHubApp;
use Falak\SourceControl\Domain\Models\Webhook;
use phpseclib3\Crypt\RSA;

/**
 * @param  array<string, mixed>  $credentials
 * @param  array<string, mixed>  $attributes
 */
function sc_connection(string $organizationId, ProviderType $provider = ProviderType::GitHub, string $authType = 'token', array $credentials = ['token' => 'tok_123'], array $attributes = []): Connection
{
    $connection = new Connection(array_merge([
        'organization_id' => $organizationId,
        'provider' => $provider,
        'name' => $provider->label().' '.bin2hex(random_bytes(3)),
        'auth_type' => $authType,
        'account' => 'octo',
    ], $attributes));
    $connection->credentials = $credentials;
    $connection->save();

    return $connection;
}

function sc_webhook(Connection $connection, string $repository = 'acme/shop', string $secret = 'topsecret'): Webhook
{
    return Webhook::query()->create([
        'organization_id' => $connection->organization_id,
        'connection_id' => $connection->id,
        'repository' => $repository,
        'secret' => $secret,
        'installed' => true,
    ]);
}

/**
 * @param  array<string, string>  $headers
 */
function sc_post_webhook(Webhook $webhook, array $payload, array $headers): TestResponse
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $server = test()->transformHeadersToServerVars(array_merge(['Content-Type' => 'application/json', 'Accept' => 'application/json'], $headers));

    return test()->call('POST', "/api/webhooks/source-control/{$webhook->id}", [], [], [], $server, $body);
}

function sc_sign(array $payload, string $secret = 'topsecret'): string
{
    return hash_hmac('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES), $secret);
}

/**
 * @return array<string, mixed> GitHub push payload
 */
function sc_github_push(string $ref = 'refs/heads/main', array $overrides = []): array
{
    return array_replace([
        'ref' => $ref,
        'before' => str_repeat('a', 40),
        'after' => str_repeat('b', 40),
        'deleted' => false,
        'head_commit' => [
            'id' => str_repeat('b', 40),
            'message' => "Fix checkout\n\nLonger body",
            'timestamp' => '2026-09-26T10:00:00+00:00',
            'url' => 'https://github.com/acme/shop/commit/'.str_repeat('b', 40),
            'author' => ['name' => 'Ada', 'email' => 'ada@example.com'],
        ],
        'pusher' => ['name' => 'ada'],
    ], $overrides);
}

/** One RSA key per test process (generation is slow). */
function sc_rsa_pem(): string
{
    static $pem = null;

    return $pem ??= RSA::createKey(2048)->toString('PKCS1');
}

/**
 * @param  array<string, mixed>  $attributes
 */
function sc_github_app(string $organizationId, array $attributes = []): GitHubApp
{
    $app = new GitHubApp(array_merge([
        'organization_id' => $organizationId,
        'app_id' => '4242',
        'slug' => 'falak-acme',
        'name' => 'Falak (acme)',
        'owner_login' => 'acme',
        'owner_type' => 'Organization',
        'html_url' => 'https://github.com/apps/falak-acme',
        'client_id' => 'Iv1.abc',
    ], $attributes));
    $app->client_secret = 'client-secret';
    $app->webhook_secret = 'hook-secret';
    $app->private_key = sc_rsa_pem();
    $app->save();

    return $app;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function sc_app_connection(string $organizationId, GitHubApp|string $app, string $installationId = '555', array $attributes = []): Connection
{
    $connection = sc_connection($organizationId, ProviderType::GitHub, 'app', ['installation_id' => $installationId, 'target_type' => 'Organization'], array_merge(['account' => 'acme'], $attributes));
    $connection->forceFill(['github_app_id' => $app instanceof GitHubApp ? $app->id : $app, 'installation_id' => $installationId])->save();

    return $connection;
}

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, string>  $headers
 */
function sc_post_app_webhook(string $appKey, string $event, array $payload, string $secret = 'hook-secret', array $headers = []): TestResponse
{
    $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
    $server = test()->transformHeadersToServerVars(array_merge([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-GitHub-Event' => $event,
        'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret),
    ], $headers));

    return test()->call('POST', "/api/webhooks/source-control/github-app/{$appKey}", [], [], [], $server, $body);
}
