<?php

namespace Falak\Edge\Application\Actions;

use Falak\Edge\Application\EdgeChanges;
use Falak\Edge\Domain\Models\Header;
use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Contracts\Data\SiteData;

final class SaveHeader
{
    public function __construct(
        private readonly EdgeChanges $changes,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  ?string  $service  public service of a compose site (null: every route of the site)
     */
    public function __invoke(SiteData $site, string $name, string $value, ?string $service = null): Header
    {
        $header = Header::query()->updateOrCreate(['site_id' => $site->id, 'compose_service' => $service, 'name' => $name], ['value' => $value]);

        $this->audit->record('edge.header_saved', 'site', $site->id, array_filter(['name' => $name, 'service' => $service]), $site->organizationId);
        $this->changes->siteChanged($site->id);

        return $header;
    }
}
