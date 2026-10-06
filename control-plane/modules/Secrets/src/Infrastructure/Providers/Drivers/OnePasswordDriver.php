<?php

namespace Falak\Secrets\Infrastructure\Providers\Drivers;

use Falak\Secrets\Domain\Models\SecretProvider;
use Falak\Secrets\Infrastructure\Providers\ProviderClient;
use Falak\Secrets\Infrastructure\Providers\ProviderFailure;

/**
 * 1Password through a Connect server (its REST API): the vault by name, the item by title, the field by label
 * (within a section when the reference names one). Service account tokens only work through 1Password's SDKs
 * and CLI, so setups without Connect run a Connect server next to Falak.
 *
 * @see https://developer.1password.com/docs/connect/api-reference/
 */
final class OnePasswordDriver implements ProviderDriver
{
    public function __construct(private readonly ProviderClient $client) {}

    public function fetch(SecretProvider $provider, array $reference, string $display): string
    {
        $vault = $this->first($provider, '/v1/vaults', 'name eq "'.$reference['vault'].'"', "the vault \"{$reference['vault']}\"");
        $item = $this->first($provider, '/v1/vaults/'.rawurlencode($vault).'/items', 'title eq "'.$reference['item'].'"', "the item \"{$reference['item']}\"");
        $data = (array) $this->get($provider, '/v1/vaults/'.rawurlencode($vault).'/items/'.rawurlencode($item), [], $display);

        $sections = [];

        foreach ((array) ($data['sections'] ?? []) as $section) {
            $sections[(string) ($section['id'] ?? '')] = (string) ($section['label'] ?? '');
        }

        $matches = array_values(array_filter((array) ($data['fields'] ?? []), function ($field) use ($reference, $sections) {
            $section = $sections[(string) ($field['section']['id'] ?? '')] ?? '';

            return ((string) ($field['label'] ?? '') === $reference['field'] || ($field['id'] ?? null) === $reference['field'])
                && ($reference['section'] === '' || $section === $reference['section']);
        }));

        if ($matches === []) {
            throw new ProviderFailure("{$display}: the item has no field \"{$reference['field']}\".");
        }

        // Never a guess between two fields of the same label.
        if (count($matches) > 1) {
            throw new ProviderFailure("{$display}: the item has several fields \"{$reference['field']}\"; name the section (op://<vault>/<item>/<section>/<field>) or rename one.");
        }

        $value = Values::scalar($matches[0]['value'] ?? null, $display);

        if ($value === '') {
            throw new ProviderFailure("{$display}: the field is empty.");
        }

        return $value;
    }

    public function test(SecretProvider $provider): void
    {
        $this->get($provider, '/v1/vaults', [], 'the vault list');
    }

    private function first(SecretProvider $provider, string $path, string $filter, string $what): string
    {
        $found = $this->get($provider, $path, ['filter' => $filter], $what);
        $found = is_array($found) ? array_values($found) : [];
        $id = $found[0]['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new ProviderFailure("1Password Connect has no {$what} (or the token can't see it).");
        }

        if (count($found) > 1) {
            throw new ProviderFailure("1Password Connect has more than one match for {$what}; rename one so the reference is unambiguous.");
        }

        return $id;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function get(SecretProvider $provider, string $path, array $query, string $what): mixed
    {
        $response = $this->client->send($provider, 'GET', rtrim((string) $provider->setting('connect_url'), '/').$path, [
            'headers' => ['Authorization' => 'Bearer '.($provider->setting('token') ?? throw new ProviderFailure('The 1Password provider has no Connect token.'))],
            'query' => $query,
        ]);

        if (! $response->successful()) {
            throw ProviderClient::failure($provider, $response, $what);
        }

        return $response->json();
    }
}
