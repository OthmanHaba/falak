<?php

namespace Kiln\Edge\Application\Actions;

use Illuminate\Support\Facades\DB;
use Kiln\Edge\Application\EdgeChanges;
use Kiln\Edge\Domain\Models\Domain;
use Kiln\Edge\Events\DomainRemoved;
use Kiln\Identity\Contracts\AuditLog;

final class RemoveDomain
{
    public function __construct(
        private readonly EdgeChanges $changes,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Domain $domain): void
    {
        DB::transaction(function () use ($domain) {
            $domain->delete();

            if ($domain->is_primary) {
                Domain::query()->where('site_id', $domain->site_id)->orderBy('created_at')->orderBy('id')->first()?->forceFill(['is_primary' => true])->save();
            }
        });

        $this->audit->record('edge.domain_removed', 'site', $domain->site_id, ['domain' => $domain->name], $domain->organization_id);

        DomainRemoved::dispatch($domain->id, $domain->site_id, $domain->organization_id, $domain->name);
        $this->changes->siteChanged($domain->site_id);
    }
}
