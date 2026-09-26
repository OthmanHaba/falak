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

    public function domainsFor(string $siteId): array
    {
        return array_map(fn (string $name) => new DomainData('d-'.$name, $siteId, $name, true, 'none', TlsMode::cases()[0], null), $this->domains[$siteId] ?? []);
    }
}
