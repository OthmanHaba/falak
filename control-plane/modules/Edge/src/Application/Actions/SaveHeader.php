<?php

namespace Kiln\Edge\Application\Actions;

use Kiln\Edge\Application\EdgeChanges;
use Kiln\Edge\Domain\Models\Header;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Sites\Contracts\Data\SiteData;

final class SaveHeader
{
    public function __construct(
        private readonly EdgeChanges $changes,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(SiteData $site, string $name, string $value): Header
    {
        $header = Header::query()->updateOrCreate(['site_id' => $site->id, 'name' => $name], ['value' => $value]);

        $this->audit->record('edge.header_saved', 'site', $site->id, ['name' => $name], $site->organizationId);
        $this->changes->siteChanged($site->id);

        return $header;
    }
}
