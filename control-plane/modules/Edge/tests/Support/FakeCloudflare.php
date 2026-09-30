<?php

namespace Kiln\Edge\Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * In-memory Cloudflare API v4 (the calls CloudflareApi makes), installed with Http::fake.
 */
final class FakeCloudflare
{
    /** @var array<string, array{id: string, name: string, account: string}> */
    public array $zones = [];

    /** @var array<string, array<string, array<string, mixed>>> zone id => record id => record */
    public array $records = [];

    /** @var array<string, array<string, mixed>> zone id => settings */
    public array $settings = [];

    /** @var array<string, array{id: string, name: string, ingress: list<array<string, mixed>>}> */
    public array $tunnels = [];

    /** Status Cloudflare reports for every tunnel (healthy, down, inactive, …). */
    public string $tunnelStatus = 'healthy';

    /** @var array<string, list<array<string, mixed>>> zone id => cache rules */
    public array $cacheRules = [];

    /** @var list<array{zone: string, hosts: list<string>}> */
    public array $purges = [];

    /** @var list<string> "METHOD path" of every call */
    public array $calls = [];

    public string $validToken = 'cf-test-token-0123456789abcdef';

    public static function install(): self
    {
        $fake = new self;
        Http::fake(['api.cloudflare.com/*' => fn (Request $request) => $fake->handle($request)]);

        return $fake;
    }

    public function zone(string $name, string $account = 'acc-1'): string
    {
        $id = 'zone-'.Str::lower(Str::random(8));
        $this->zones[$id] = ['id' => $id, 'name' => $name, 'account' => $account];
        $this->records[$id] = [];
        $this->settings[$id] = ['ssl' => 'full', 'min_tls_version' => '1.0', 'always_use_https' => 'off'];

        return $id;
    }

    /** @param  array<string, mixed>  $record */
    public function put(string $zoneId, array $record): string
    {
        $id = 'rec-'.Str::lower(Str::random(10));
        $this->records[$zoneId][$id] = ['id' => $id, 'proxied' => false, 'comment' => null, ...$record];

        return $id;
    }

    /** @return list<array<string, mixed>> */
    public function recordsOf(string $zoneId): array
    {
        return array_values($this->records[$zoneId] ?? []);
    }

    private function handle(Request $request): mixed
    {
        $path = (string) preg_replace('#^https://api\.cloudflare\.com/client/v4#', '', strtok($request->url(), '?'));
        $this->calls[] = "{$request->method()} {$path}";

        if ($request->header('Authorization')[0] !== "Bearer {$this->validToken}") {
            return Http::response(['success' => false, 'errors' => [['code' => 1000, 'message' => 'Invalid API Token']], 'result' => null], 401);
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $body = $request->data();
        $ok = fn ($result) => Http::response(['success' => true, 'errors' => [], 'result' => $result]);

        return match (true) {
            $path === '/user/tokens/verify' => $ok(['status' => 'active']),
            $path === '/zones' => $ok(array_values(array_map(fn ($z) => ['id' => $z['id'], 'name' => $z['name'], 'status' => 'active', 'plan' => ['name' => 'Free Website'], 'account' => ['id' => $z['account']]], $this->zones))),
            (bool) preg_match('#^/zones/([^/]+)/dns_records$#', $path, $m) && $request->method() === 'GET' => $ok(array_values(array_filter($this->records[$m[1]] ?? [], fn ($r) => $r['name'] === ($query['name_exact'] ?? $query['name.exact'] ?? $r['name'])))),
            (bool) preg_match('#^/zones/([^/]+)/dns_records$#', $path, $m) && $request->method() === 'POST' => $ok($this->records[$m[1]][$id = $this->put($m[1], $body)]),
            (bool) preg_match('#^/zones/([^/]+)/dns_records/([^/]+)$#', $path, $m) && $request->method() === 'PATCH' => $ok($this->records[$m[1]][$m[2]] = [...$this->records[$m[1]][$m[2]], ...$body]),
            (bool) preg_match('#^/zones/([^/]+)/dns_records/([^/]+)$#', $path, $m) && $request->method() === 'DELETE' => (function () use ($m, $ok) {
                if (! isset($this->records[$m[1]][$m[2]])) {
                    return Http::response(['success' => false, 'errors' => [['code' => 81044, 'message' => 'Record does not exist.']]], 404);
                }
                unset($this->records[$m[1]][$m[2]]);

                return $ok(['id' => $m[2]]);
            })(),
            (bool) preg_match('#^/zones/([^/]+)/settings/([a-z_]+)$#', $path, $m) && $request->method() === 'GET' => $ok(['id' => $m[2], 'value' => $this->settings[$m[1]][$m[2]] ?? null]),
            (bool) preg_match('#^/zones/([^/]+)/settings/([a-z_]+)$#', $path, $m) && $request->method() === 'PATCH' => $ok(['id' => $m[2], 'value' => $this->settings[$m[1]][$m[2]] = $body['value']]),
            (bool) preg_match('#^/zones/([^/]+)/rulesets/phases/http_request_cache_settings/entrypoint$#', $path, $m) && $request->method() === 'GET' => isset($this->cacheRules[$m[1]])
                ? $ok(['id' => 'rs-1', 'rules' => $this->cacheRules[$m[1]]])
                : Http::response(['success' => false, 'errors' => [['code' => 10003, 'message' => 'could not find entrypoint ruleset']]], 404),
            (bool) preg_match('#^/zones/([^/]+)/rulesets/phases/http_request_cache_settings/entrypoint$#', $path, $m) && $request->method() === 'PUT' => $ok(['id' => 'rs-1', 'rules' => $this->cacheRules[$m[1]] = $body['rules']]),
            (bool) preg_match('#^/zones/([^/]+)/purge_cache$#', $path, $m) => (function () use ($m, $body, $ok) {
                $this->purges[] = ['zone' => $m[1], 'hosts' => $body['hosts'] ?? []];

                return $ok(['id' => 'purge']);
            })(),
            (bool) preg_match('#^/accounts/[^/]+/cfd_tunnel$#', $path) && $request->method() === 'POST' => $ok($this->tunnels[$tid = 'tun-'.Str::lower(Str::random(8))] = ['id' => $tid, 'name' => $body['name'], 'ingress' => []]),
            (bool) preg_match('#^/accounts/[^/]+/cfd_tunnel/([^/]+)/token$#', $path, $m) => $ok('token-for-'.$m[1]),
            (bool) preg_match('#^/accounts/[^/]+/cfd_tunnel/([^/]+)/configurations$#', $path, $m) => (function () use ($m, $body, $ok) {
                $this->tunnels[$m[1]]['ingress'] = $body['config']['ingress'];

                return $ok(['tunnel_id' => $m[1]]);
            })(),
            (bool) preg_match('#^/accounts/[^/]+/cfd_tunnel/([^/]+)/connections$#', $path) => $ok([]),
            (bool) preg_match('#^/accounts/[^/]+/cfd_tunnel/([^/]+)$#', $path, $m) && $request->method() === 'GET' => $ok(['id' => $m[1], 'status' => $this->tunnelStatus, 'connections' => $this->tunnelStatus === 'healthy' ? [['id' => 'c1'], ['id' => 'c2'], ['id' => 'c3'], ['id' => 'c4']] : []]),
            (bool) preg_match('#^/accounts/[^/]+/cfd_tunnel/([^/]+)$#', $path, $m) && $request->method() === 'DELETE' => (function () use ($m, $ok) {
                unset($this->tunnels[$m[1]]);

                return $ok(['id' => $m[1]]);
            })(),
            default => Http::response(['success' => false, 'errors' => [['code' => 7003, 'message' => "No route for {$request->method()} {$path}"]]], 400),
        };
    }
}
