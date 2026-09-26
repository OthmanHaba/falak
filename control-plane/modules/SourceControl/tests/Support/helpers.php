<?php

use Illuminate\Testing\TestResponse;
use Kiln\SourceControl\Contracts\ProviderType;
use Kiln\SourceControl\Domain\Models\Connection;
use Kiln\SourceControl\Domain\Models\Webhook;

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
