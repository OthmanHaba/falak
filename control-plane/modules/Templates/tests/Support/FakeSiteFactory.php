<?php

namespace Falak\Templates\Tests\Support;

use Illuminate\Validation\ValidationException;
use Falak\Sites\Contracts\Data\CreatedSite;
use Falak\Sites\Contracts\Data\SitePlacement;
use Falak\Sites\Contracts\SiteFactory;
use Falak\Sites\Infrastructure\ActionSiteFactory;

/**
 * Records the payload DeployTemplate hands to Sites (the §5 compose fields the real factory does not accept until
 * the compose runtime merges), then creates a real *docker* site with the non-compose fields so placement,
 * SiteCreated and the canvas behave like production.
 */
final class FakeSiteFactory implements SiteFactory
{
    /** @var list<array{organization_id: string, user_id: ?string, data: array<string, mixed>, placement: ?SitePlacement}> */
    public array $created = [];

    /** @var list<string> slugs to reject as taken */
    public array $takenSlugs = [];

    public function create(string $organizationId, ?string $userId, array $data, ?SitePlacement $placement = null): CreatedSite
    {
        if (in_array($data['slug'] ?? null, $this->takenSlugs, true)) {
            throw ValidationException::withMessages(['slug' => 'The slug has already been taken.']);
        }

        $this->created[] = ['organization_id' => $organizationId, 'user_id' => $userId, 'data' => $data, 'placement' => $placement];

        $real = array_diff_key($data, array_flip(['compose_source', 'compose_content', 'compose_file', 'public_services', 'variables', 'template']));
        $real['runtime'] = 'docker';
        $real['docker_image'] = 'nginx:1.29';

        return app(ActionSiteFactory::class)->create($organizationId, $userId, $real, $placement);
    }

    public function duplicate(string $siteId, array $overrides = [], ?SitePlacement $placement = null, ?string $userId = null): CreatedSite
    {
        return app(ActionSiteFactory::class)->duplicate($siteId, $overrides, $placement, $userId);
    }

    public function delete(string $siteId, bool $deleteVolumes = false): void
    {
        app(ActionSiteFactory::class)->delete($siteId, $deleteVolumes);
    }

    /**
     * @return array<string, mixed>
     */
    public function last(): array
    {
        return end($this->created)['data'] ?? [];
    }
}
