<?php

namespace Falak\Sites\Infrastructure;

use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\SiteDomains;
use Falak\Sites\Contracts\SiteHeaders;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;

final class EloquentSiteHeaders implements SiteHeaders
{
    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly SiteDomains $domains,
    ) {}

    public function for(string $siteId): array
    {
        return $this->forSite(Site::query()->with('targets')->findOrFail($siteId));
    }

    /**
     * @return array{id: string, name: string, slug: string, runtime: string, runtime_label: string, framework_label: string, repository: ?string, branch: ?string, primary_domain: ?string, test_domain: ?string, servers: list<array{id: string, name: string, role: string}>}
     */
    public function forSite(Site $site): array
    {
        return [
            'id' => $site->id,
            'name' => $site->name,
            'slug' => $site->slug,
            'runtime' => $site->runtime->value,
            'runtime_label' => $site->runtime->label(),
            'framework_label' => $site->framework->label(),
            'repository' => $site->repository,
            'branch' => $site->branch,
            'primary_domain' => $this->domains->primaryDomains([$site->id])[$site->id] ?? null,
            'test_domain' => $site->testDomain(),
            'servers' => $site->targets->map(fn (SiteTarget $target) => [
                'id' => $target->server_id,
                'name' => $this->servers->find($target->server_id)?->name ?? 'deleted server',
                'role' => $target->role->value,
            ])->values()->all(),
        ];
    }
}
