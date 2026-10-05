<?php

namespace Falak\Sites\Infrastructure;

use Falak\Sites\Application\Actions\UpdateSite;
use Falak\Sites\Contracts\SiteDeploySettings;
use Falak\Sites\Domain\Models\Site;

final class EloquentSiteDeploySettings implements SiteDeploySettings
{
    public function setPushToDeploy(string $siteId, bool $enabled, ?string $actorId = null): array
    {
        $site = Site::query()->findOrFail($siteId);
        $update = app(UpdateSite::class);
        $update($site, ['push_to_deploy' => $enabled]);

        return $update->warnings;
    }
}
