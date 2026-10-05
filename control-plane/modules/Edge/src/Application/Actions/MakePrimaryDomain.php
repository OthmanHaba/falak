<?php

namespace Falak\Edge\Application\Actions;

use Illuminate\Support\Facades\DB;
use Falak\Edge\Application\ComposeServiceDomains;
use Falak\Edge\Application\EdgeChanges;
use Falak\Edge\Domain\Models\Domain;
use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Contracts\SiteDirectory;

final class MakePrimaryDomain
{
    public function __construct(
        private readonly EdgeChanges $changes,
        private readonly AuditLog $audit,
        private readonly ComposeServiceDomains $services,
        private readonly SiteDirectory $sites,
    ) {}

    public function __invoke(Domain $domain): void
    {
        if ($domain->is_primary) {
            return;
        }

        DB::transaction(function () use ($domain) {
            // Primary within its route: the site's own domains, or one compose service's.
            $site = $this->sites->find($domain->site_id);
            $query = Domain::query()->where('site_id', $domain->site_id);
            ($site !== null ? ComposeServiceDomains::scope($query, $site, $domain->compose_service) : $query)->update(['is_primary' => false]);
            $domain->forceFill(['is_primary' => true])->save();
        });

        $this->audit->record('edge.domain_primary', 'site', $domain->site_id, ['domain' => $domain->name], $domain->organization_id);
        $this->changes->siteChanged($domain->site_id);
        $this->services->mirror($domain->site_id);
    }
}
