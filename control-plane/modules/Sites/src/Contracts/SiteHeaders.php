<?php

namespace Kiln\Sites\Contracts;

/**
 * The `site` prop every /sites/{id}/* Inertia page passes to the shared SiteLayout
 * (resources/js/layouts/site-layout.tsx), so other modules' site tabs render the same header.
 */
interface SiteHeaders
{
    /**
     * @return array{id: string, name: string, slug: string, runtime: string, runtime_label: string, framework_label: string, repository: ?string, branch: ?string, primary_domain: ?string, test_domain: ?string, servers: list<array{id: string, name: string, role: string}>}
     */
    public function for(string $siteId): array;
}
