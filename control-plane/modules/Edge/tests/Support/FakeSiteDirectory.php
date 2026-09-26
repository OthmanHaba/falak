<?php

namespace Kiln\Edge\Tests\Support;

use Kiln\Sites\Contracts\Data\EnvironmentData;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\Data\SiteTargetData;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\SiteHeaders;

/**
 * In-memory SiteDirectory + SiteHeaders implementing only the public Sites contracts.
 */
final class FakeSiteDirectory implements SiteDirectory, SiteHeaders
{
    /** @var array<string, SiteData> */
    public array $sites = [];

    public function put(SiteData $site): SiteData
    {
        return $this->sites[$site->id] = $site;
    }

    public function forget(string $siteId): void
    {
        unset($this->sites[$siteId]);
    }

    public function find(string $siteId): ?SiteData
    {
        return $this->sites[$siteId] ?? null;
    }

    public function forOrganization(string $organizationId): array
    {
        return array_values(array_filter($this->sites, fn (SiteData $site) => $site->organizationId === $organizationId));
    }

    public function forServer(string $serverId): array
    {
        return array_values(array_filter($this->sites, fn (SiteData $site) => in_array($serverId, $site->serverIds(), true)));
    }

    public function forRepository(string $connectionId, string $repository, ?string $branch = null): array
    {
        return [];
    }

    public function targets(string $siteId): array
    {
        return $this->sites[$siteId]->targets ?? [];
    }

    public function leader(string $siteId): ?SiteTargetData
    {
        return $this->find($siteId)?->leader();
    }

    public function environment(string $siteId, ?int $version = null): ?EnvironmentData
    {
        return null;
    }

    public function sharedPaths(string $siteId): array
    {
        return [];
    }

    public function deployVariables(string $siteId, string $serverId, array $context = []): array
    {
        return $context;
    }

    public function for(string $siteId): array
    {
        $site = $this->sites[$siteId];

        return [
            'id' => $site->id,
            'name' => $site->name,
            'slug' => $site->slug,
            'runtime' => $site->runtime->value,
            'runtime_label' => $site->runtime->label(),
            'framework_label' => $site->framework->label(),
            'repository' => $site->repository,
            'branch' => $site->branch,
            'primary_domain' => null,
            'test_domain' => $site->testDomain,
            'servers' => array_map(fn (SiteTargetData $t) => ['id' => $t->serverId, 'name' => $t->serverId, 'role' => $t->role->value], $site->targets),
        ];
    }
}
