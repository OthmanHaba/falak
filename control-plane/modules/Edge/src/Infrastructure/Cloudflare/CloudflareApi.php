<?php

namespace Kiln\Edge\Infrastructure\Cloudflare;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/**
 * Cloudflare API v4 with one API token. Every call returns Cloudflare's `result` or throws CloudflareError with the
 * API's own message, so it can be shown to the user as is.
 */
final class CloudflareApi
{
    public const BASE = 'https://api.cloudflare.com/client/v4';

    public function __construct(#[SensitiveParameter] private readonly string $token) {}

    public static function with(#[SensitiveParameter] string $token): self
    {
        return new self($token);
    }

    /** Token status ("active") — user tokens verify at /user/tokens, account tokens at /accounts/{id}/tokens. */
    public function verify(): string
    {
        try {
            return (string) ($this->call('GET', '/user/tokens/verify')['status'] ?? '');
        } catch (CloudflareError $e) {
            if ($e->status !== 401 && $e->status !== 403) {
                throw $e;
            }
            $accounts = $this->call('GET', '/accounts', ['per_page' => 5]);
            if (($id = $accounts[0]['id'] ?? null) === null) {
                throw $e;
            }

            return (string) ($this->call('GET', "/accounts/{$id}/tokens/verify")['status'] ?? '');
        }
    }

    /**
     * Zones the token can see.
     *
     * @return list<array{id: string, name: string, status: string, plan: string, account_id: ?string}>
     */
    public function zones(): array
    {
        $zones = [];

        for ($page = 1; $page <= 20; $page++) {
            $batch = $this->call('GET', '/zones', ['per_page' => 50, 'page' => $page]);
            foreach ($batch as $zone) {
                $zones[] = ['id' => (string) $zone['id'], 'name' => (string) $zone['name'], 'status' => (string) ($zone['status'] ?? ''),
                    'plan' => (string) ($zone['plan']['name'] ?? ''), 'account_id' => isset($zone['account']['id']) ? (string) $zone['account']['id'] : null];
            }
            if (count($batch) < 50) {
                break;
            }
        }

        return $zones;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function records(string $zoneId, string $name): array
    {
        return array_values($this->call('GET', "/zones/{$zoneId}/dns_records", ['name.exact' => $name, 'per_page' => 100]));
    }

    /**
     * @param  array{type: string, name: string, content: string, proxied: bool, comment: string}  $record
     * @return array<string, mixed>
     */
    public function createRecord(string $zoneId, array $record): array
    {
        return $this->call('POST', "/zones/{$zoneId}/dns_records", [...$record, 'ttl' => 1]);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function updateRecord(string $zoneId, string $recordId, array $fields): array
    {
        return $this->call('PATCH', "/zones/{$zoneId}/dns_records/{$recordId}", $fields);
    }

    /** Deleting a record that is already gone is not an error. */
    public function deleteRecord(string $zoneId, string $recordId): void
    {
        try {
            $this->call('DELETE', "/zones/{$zoneId}/dns_records/{$recordId}");
        } catch (CloudflareError $e) {
            if ($e->status !== 404) {
                throw $e;
            }
        }
    }

    public function setting(string $zoneId, string $key): mixed
    {
        return $this->call('GET', "/zones/{$zoneId}/settings/{$key}")['value'] ?? null;
    }

    public function updateSetting(string $zoneId, string $key, mixed $value): mixed
    {
        return $this->call('PATCH', "/zones/{$zoneId}/settings/{$key}", ['value' => $value])['value'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $data  query (GET) or JSON body
     * @return array<int|string, mixed>
     */
    private function call(string $method, string $path, array $data = []): array
    {
        $idempotent = in_array($method, ['GET', 'DELETE'], true);

        try {
            $request = $this->client()->retry($idempotent ? 3 : 1, 400, throw: false);
            $response = $method === 'GET' ? $request->get($path, $data) : $request->send($method, $path, $data === [] ? [] : ['json' => $data]);
        } catch (ConnectionException $e) {
            throw new CloudflareError('Cloudflare is unreachable: '.$e->getMessage(), 0);
        }

        $body = $response->json();

        if ($response->failed() || ! is_array($body) || ($body['success'] ?? false) !== true) {
            $errors = is_array($body['errors'] ?? null) ? $body['errors'] : [];
            $message = implode('; ', array_map(fn ($e) => (string) (is_array($e) ? ($e['message'] ?? '') : $e), $errors)) ?: "HTTP {$response->status()}";

            throw new CloudflareError("Cloudflare: {$message}", $response->status(), (int) ($errors[0]['code'] ?? 0));
        }

        return is_array($body['result'] ?? null) ? $body['result'] : [];
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE)->withToken($this->token)->acceptJson()->timeout(20)->connectTimeout(8);
    }
}
