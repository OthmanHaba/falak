<?php

namespace Kiln\Sites\Application\Actions;

use Kiln\Identity\Contracts\AuditLog;
use Kiln\Sites\Application\SourceControlLinker;
use Kiln\Sites\Application\TargetProvisioner;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Events\SiteDeleted;

/**
 * Delete a site: remove PHP-FPM pools and provider hooks; Edge drops its routes on SiteDeleted.
 * Files under /srv/kiln/sites/<slug> are left on the servers.
 */
final class DeleteSite
{
    public function __construct(
        private readonly TargetProvisioner $provisioner,
        private readonly SourceControlLinker $sourceControl,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Site $site, bool $cleanupRemote = true): void
    {
        $site->loadMissing('targets');
        $serverIds = $site->serverIds();

        if ($cleanupRemote && $site->runtime === SiteRuntime::PhpFpm && $site->php_version) {
            foreach ($serverIds as $serverId) {
                $this->provisioner->removePool($site, $serverId, $site->php_version);
            }
        }

        if ($cleanupRemote) {
            $this->sourceControl->unlink($site->id, $site->source_connection_id, $site->repository, $site->deploy_key_id);
        }

        $site->delete();

        $this->audit->record('site.deleted', 'site', $site->id, ['name' => $site->name, 'slug' => $site->slug], $site->organization_id);
        SiteDeleted::dispatch($site->id, $site->organization_id, $site->slug, $serverIds);
    }
}
