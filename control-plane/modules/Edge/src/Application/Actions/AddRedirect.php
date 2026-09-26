<?php

namespace Kiln\Edge\Application\Actions;

use Kiln\Edge\Application\EdgeChanges;
use Kiln\Edge\Domain\Models\Redirect;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Sites\Contracts\Data\SiteData;

final class AddRedirect
{
    public function __construct(
        private readonly EdgeChanges $changes,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(SiteData $site, string $from, string $to, int $status): Redirect
    {
        $redirect = Redirect::query()->create([
            'site_id' => $site->id,
            'from' => $from,
            'to' => $to,
            'status' => $status,
            'position' => (int) Redirect::query()->where('site_id', $site->id)->max('position') + 1,
        ]);

        $this->audit->record('edge.redirect_added', 'site', $site->id, ['from' => $from, 'to' => $to, 'status' => $status], $site->organizationId);
        $this->changes->siteChanged($site->id);

        return $redirect;
    }
}
