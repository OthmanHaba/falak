<?php

namespace Kiln\Edge\Application\Actions;

use Illuminate\Support\Facades\DB;
use Kiln\Edge\Application\ComposeServiceDomains;
use Kiln\Edge\Application\EdgeChanges;
use Kiln\Edge\Domain\Models\Domain;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Sites\Contracts\SiteDirectory;

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
