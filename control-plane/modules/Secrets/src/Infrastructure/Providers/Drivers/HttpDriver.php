<?php

namespace Falak\Secrets\Infrastructure\Providers\Drivers;

use Falak\Secrets\Domain\Models\SecretProvider;
use Falak\Secrets\Infrastructure\Providers\ProviderClient;
use Falak\Secrets\Infrastructure\Providers\ProviderFailure;
use Illuminate\Http\Client\Response;

/**
 * A generic HTTPS webhook (the model of External Secrets' webhook provider), for anything without a native
 * driver. Contract (docs/SECRET_PROVIDERS.md): `GET {base_url}?ref=<ref>` with the configured header, answering
 * `200 {"value": "…"}`; a linked secret's reference is `{base_url}/<ref>`.
 */
final class HttpDriver implements ProviderDriver
{
    /** The ref a connection test asks for: any 2xx or a 404 proves reachability and accepted credentials. */
    public const TEST_REF = '__falak_connection_test__';

    public function __construct(private readonly ProviderClient $client) {}

    public function fetch(SecretProvider $provider, array $reference, string $display): string
    {
        $response = $this->get($provider, $reference['ref']);

        if (! $response->successful()) {
            throw ProviderClient::failure($provider, $response, $display);
        }

        $json = $response->json();

        if (! is_array($json) || ! array_key_exists('value', $json)) {
            throw new ProviderFailure("{$display}: the webhook did not answer {\"value\": \"…\"}.");
        }

        return Values::scalar($json['value'], $display);
    }

    public function test(SecretProvider $provider): void
    {
        $response = $this->get($provider, self::TEST_REF);

        if (! $response->successful() && $response->status() !== 404) {
            throw ProviderClient::failure($provider, $response, 'the connection test');
        }
    }

    private function get(SecretProvider $provider, string $ref): Response
    {
        $header = $provider->setting('header_name');
        $value = $provider->setting('header_value');

        return $this->client->send($provider, 'GET', rtrim((string) $provider->setting('base_url'), '/'), [
            'headers' => $header !== null && $value !== null ? [$header => $value] : [],
            'query' => ['ref' => $ref],
        ]);
    }
}
