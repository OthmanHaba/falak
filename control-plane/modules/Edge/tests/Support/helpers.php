<?php

use Illuminate\Support\Str;
use Kiln\Edge\Contracts\EdgeRoutes;
use Kiln\Edge\Tests\Support\FakeServerDirectory;
use Kiln\Edge\Tests\Support\FakeSiteDirectory;
use Kiln\Edge\Tests\Support\RecordingAgentGateway;
use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Fleet\Infrastructure\ProtocolSchemas;
use Kiln\Servers\Contracts\Data\ServerData;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Contracts\ServerType;
use Kiln\Sites\Contracts\BuildMode;
use Kiln\Sites\Contracts\Data\LaravelSettings;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\Data\SiteTargetData;
use Kiln\Sites\Contracts\Framework;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\SiteHeaders;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Contracts\TargetRole;
use Kiln\Sites\Contracts\TargetStatus;

/**
 * Bind in-memory Sites / Servers directories and a schema-validating recording AgentGateway.
 *
 * @return array{sites: FakeSiteDirectory, servers: FakeServerDirectory, agents: RecordingAgentGateway}
 */
function edge_fakes(): array
{
    $sites = new FakeSiteDirectory;
    $servers = new FakeServerDirectory;
    $agents = new RecordingAgentGateway(fn (string $type, array $payload) => edge_schema_errors($type, $payload));

    app()->instance(SiteDirectory::class, $sites);
    app()->instance(SiteHeaders::class, $sites);
    app()->instance(ServerDirectory::class, $servers);
    app()->instance(AgentGateway::class, $agents);

    return ['sites' => $sites, 'servers' => $servers, 'agents' => $agents];
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, list<string>>
 */
function edge_schema_errors(string $type, array $payload): array
{
    return app(ProtocolSchemas::class)->validateCommand($type, ProtocolSchemas::toJson($payload));
}

function edge_server(FakeServerDirectory $servers, string $organizationId, array $overrides = []): ServerData
{
    $args = array_replace([
        'id' => (string) Str::ulid(),
        'organizationId' => $organizationId,
        'name' => 'web-'.Str::lower(Str::random(4)),
        'type' => ServerType::Web,
        'status' => ServerStatus::Active,
        'provider' => 'custom',
        'ipv4' => '203.0.113.'.random_int(2, 250),
        'ipv6' => null,
        'privateIpv4' => '10.0.0.'.random_int(2, 250),
        'arch' => 'amd64',
        'phpVersions' => ['8.4'],
        'defaultPhpVersion' => '8.4',
        'phpRuntime' => 'frankenphp',
        'nodeVersion' => null,
        'databaseEngine' => null,
        'cacheEngine' => null,
        'docker' => false,
        'unixUser' => 'kiln',
    ], $overrides);

    return $servers->put(new ServerData(...$args));
}

/**
 * @param  list<string>  $serverIds  first one is the leader
 */
function edge_site(FakeSiteDirectory $sites, string $organizationId, array $serverIds, array $overrides = []): SiteData
{
    $id = $overrides['id'] ?? (string) Str::ulid();
    $slug = $overrides['slug'] ?? 'shop-'.Str::lower(Str::random(4));

    $args = array_replace([
        'id' => $id,
        'organizationId' => $organizationId,
        'name' => 'Shop',
        'slug' => $slug,
        'runtime' => SiteRuntime::FrankenPhp,
        'buildMode' => BuildMode::Native,
        'framework' => Framework::Laravel,
        'phpVersion' => '8.4',
        'nodeVersion' => null,
        'sourceConnectionId' => null,
        'repository' => 'acme/shop',
        'branch' => 'main',
        'deployKeyId' => null,
        'pushToDeploy' => false,
        'rootPath' => "/srv/kiln/sites/{$slug}",
        'webDirectory' => 'public',
        'unixUser' => 'kiln',
        'isolated' => false,
        'appPort' => null,
        'dockerImage' => null,
        'dockerfile' => null,
        'composeFile' => null,
        'healthCheckPath' => null,
        'deployScript' => '',
        'laravel' => new LaravelSettings,
        'testDomain' => null,
        'sharedPaths' => [],
        'targets' => array_map(fn (string $serverId, int $i) => new SiteTargetData(
            (string) Str::ulid(), $id, $serverId, $i === 0 ? TargetRole::Leader : TargetRole::Member, TargetStatus::Ready,
        ), $serverIds, array_keys($serverIds)),
    ], $overrides);

    return $sites->put(new SiteData(...$args));
}

/**
 * Compiled edge.caddy.apply payload for a server, asserting it is schema-valid.
 *
 * @return array<string, mixed>
 */
function edge_compile(string $serverId): array
{
    $payload = app(EdgeRoutes::class)->compile($serverId);
    expect(edge_schema_errors('edge.caddy.apply', $payload))->toBe([]);

    return $payload;
}

/**
 * @return array<string, mixed>|null
 */
function edge_entry(array $payload, string $id): ?array
{
    foreach ($payload['sites'] as $entry) {
        if ($entry['id'] === $id) {
            return $entry;
        }
    }

    return null;
}

/**
 * Self-signed certificate + key for $domains.
 *
 * @param  list<string>  $domains
 * @return array{cert: string, key: string}
 */
function edge_self_signed(array $domains, int $days = 90): array
{
    $config = tempnam(sys_get_temp_dir(), 'kiln-openssl');
    file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[san]\nsubjectAltName=".implode(',', array_map(fn ($d) => "DNS:{$d}", $domains))."\n");

    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'config' => $config]);
    $csr = openssl_csr_new(['commonName' => $domains[0]], $key, ['digest_alg' => 'sha256', 'config' => $config]);
    $cert = openssl_csr_sign($csr, null, $key, $days, ['digest_alg' => 'sha256', 'config' => $config, 'x509_extensions' => 'san'], random_int(1, PHP_INT_MAX));

    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($key, $keyPem);
    unlink($config);

    return ['cert' => $certPem, 'key' => $keyPem];
}
