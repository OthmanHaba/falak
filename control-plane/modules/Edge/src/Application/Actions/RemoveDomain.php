<?php

namespace Kiln\Edge\Application\Actions;

use Illuminate\Support\Facades\DB;
use Kiln\Edge\Application\ComposeServiceDomains;
use Kiln\Edge\Application\EdgeChanges;
use Kiln\Edge\Domain\Models\Domain;
use Kiln\Edge\Events\DomainRemoved;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Sites\Contracts\SiteDirectory;

final class RemoveDomain
{
    public function __construct(
        private readonly EdgeChanges $changes,
        private readonly AuditLog $audit,
        private readonly ComposeServiceDomains $services,
        private readonly SiteDirectory $sites,
    ) {}

    public function __invoke(Domain $domain): void
    {
        DB::transaction(function () use ($domain) {
            $domain->delete();

            if ($domain->is_primary) {
                // The next domain of the same route (the site's own, or the compose service's) becomes primary.
                $site = $this->sites->find($domain->site_id);
                $query = Domain::query()->where('site_id', $domain->site_id);
                ($site !== null ? ComposeServiceDomains::scope($query, $site, $domain->compose_service) : $query)
                    ->orderBy('created_at')->orderBy('id')->first()?->forceFill(['is_primary' => true])->save();
            }
        });

        $this->audit->record('edge.domain_removed', 'site', $domain->site_id, ['domain' => $domain->name], $domain->organization_id);

        DomainRemoved::dispatch($domain->id, $domain->site_id, $domain->organization_id, $domain->name);
        $this->changes->siteChanged($domain->site_id);
        $this->services->mirror($domain->site_id);
    }
}
