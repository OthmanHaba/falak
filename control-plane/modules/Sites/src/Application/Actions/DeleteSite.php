<?php

namespace Falak\Sites\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Application\SourceControlLinker;
use Falak\Sites\Application\TargetProvisioner;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Events\SiteDeleted;

/**
 * Delete a site: remove PHP-FPM pools, containers / compose projects and provider hooks; Edge drops its routes and
 * Processes its programs on SiteDeleted. Files under /srv/falak/sites/<slug> are left on the servers, and compose
 * named volumes too unless $deleteVolumes.
 */
final class DeleteSite
{
    public function __construct(
        private readonly TargetProvisioner $provisioner,
        private readonly SourceControlLinker $sourceControl,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Site $site, bool $cleanupRemote = true, bool $deleteVolumes = false): void
    {
        $site->loadMissing('targets');
        $serverIds = $site->serverIds();

        if ($cleanupRemote && $site->runtime === SiteRuntime::PhpFpm && $site->php_version) {
            foreach ($serverIds as $serverId) {
                $this->provisioner->removePool($site, $serverId, $site->php_version);
            }
        }

        if ($cleanupRemote && $site->runtime->usesDocker()) {
            foreach ($serverIds as $serverId) {
                $this->provisioner->removeContainers($site, $serverId, $deleteVolumes);
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
