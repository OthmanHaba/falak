<?php

namespace Kiln\Sites\Infrastructure;

use Kiln\Sites\Application\Actions\UpdateSite;
use Kiln\Sites\Contracts\SiteDeploySettings;
use Kiln\Sites\Domain\Models\Site;

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
