<?php

namespace Kiln\Edge\Application\Actions;

use Illuminate\Support\Facades\DB;
use Kiln\Edge\Application\EdgeChanges;
use Kiln\Edge\Domain\Models\Domain;
use Kiln\Identity\Contracts\AuditLog;

final class MakePrimaryDomain
{
    public function __construct(
        private readonly EdgeChanges $changes,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Domain $domain): void
    {
        if ($domain->is_primary) {
            return;
        }

        DB::transaction(function () use ($domain) {
            Domain::query()->where('site_id', $domain->site_id)->update(['is_primary' => false]);
            $domain->forceFill(['is_primary' => true])->save();
        });

        $this->audit->record('edge.domain_primary', 'site', $domain->site_id, ['domain' => $domain->name], $domain->organization_id);
        $this->changes->siteChanged($domain->site_id);
    }
}
