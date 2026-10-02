<?php

namespace Kiln\Deployments\Tests\Support;

use Kiln\Edge\Contracts\Data\DomainData;
use Kiln\Edge\Contracts\EdgeRoutes;
use Kiln\Edge\Contracts\TlsMode;
use Kiln\Fleet\Contracts\Data\CommandHandle;

final class FakeEdgeRoutes implements EdgeRoutes
{
    /** @var list<array{site: string, server: string, upstream: string}> */
    public array $upstreams = [];

    /** @var array<string, list<string>> */
    public array $domains = [];

    /** @var array<string, TlsMode> TLS per domain name (default Auto) */
    public array $domainTls = [];

    public TlsMode $testDomainTlsMode = TlsMode::Auto;

    public static function install(): self
    {
        $fake = new self;
        app()->instance(EdgeRoutes::class, $fake);

        return $fake;
    }

    public function compile(string $serverId): array
    {
        return [];
    }

    public function apply(string $serverId, bool $force = false): ?CommandHandle
    {
        return null;
    }

    public function schedule(string ...$serverIds): void {}

    public function recordUpstream(string $siteId, string $serverId, string $upstream): void
    {
        $this->upstreams[] = ['site' => $siteId, 'server' => $serverId, 'upstream' => $upstream];
    }

    public function routeId(string $siteId): string
    {
        return "site-{$siteId}";
    }

    /** Domains of a compose service are keyed "<site id>:<service>" in $domains. */
    public function domainsFor(string $siteId, ?string $service = null): array
    {
        $key = $service === null ? $siteId : "{$siteId}:{$service}";

        return array_map(fn (string $name) => new DomainData('d-'.$name, $siteId, $name, true, 'none', $this->domainTls[$name] ?? TlsMode::Auto, null, $service), $this->domains[$key] ?? []);
    }

    public function testDomainTls(): TlsMode
    {
        return $this->testDomainTlsMode;
    }

    public function proxiesToOctane(string $siteId, string $serverId): bool
    {
        return false;
    }
}
