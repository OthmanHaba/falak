<?php

namespace Kiln\Edge\Infrastructure;

use Kiln\Edge\Domain\Models\Domain;
use Kiln\Sites\Contracts\SiteDomains;

final class EloquentSiteDomains implements SiteDomains
{
    public function primaryDomains(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        return Domain::query()
            ->whereIn('site_id', $siteIds)
            ->where('is_primary', true)
            ->get(['site_id', 'name', 'www_redirect'])
            ->mapWithKeys(fn (Domain $domain) => [$domain->site_id => $domain->servedHost()])
            ->all();
    }
}
