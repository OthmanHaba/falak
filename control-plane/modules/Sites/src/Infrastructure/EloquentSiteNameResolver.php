<?php

namespace Kiln\Sites\Infrastructure;

use Kiln\Insights\Contracts\SiteNameResolver;
use Kiln\Sites\Domain\Models\Site;

/**
 * Site display names for Insights (deleted sites fall back to their id).
 */
final class EloquentSiteNameResolver implements SiteNameResolver
{
    public function name(string $siteId): string
    {
        return $this->names([$siteId])[$siteId];
    }

    public function names(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        $names = Site::query()->whereIn('id', array_map('strtolower', $siteIds))->pluck('name', 'id')->all();
        $out = [];

        foreach ($siteIds as $siteId) {
            $out[$siteId] = $names[strtolower($siteId)] ?? $siteId;
        }

        return $out;
    }
}
