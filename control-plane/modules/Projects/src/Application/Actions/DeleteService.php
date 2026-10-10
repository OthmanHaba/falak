<?php

namespace Falak\Projects\Application\Actions;

use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Databases\Contracts\DatabaseProvisioner;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Projects\Domain\Models\Service;
use Falak\Sites\Contracts\SiteFactory;

/**
 * The panel's Delete: deletes the site / database through its owning module. The card leaves the canvas when
 * that module reports the deletion (SiteDeleted right away; DatabaseDeleted once the agent removed its container).
 */
final class DeleteService
{
    public function __construct(
        private readonly SiteFactory $sites,
        private readonly DatabaseProvisioner $databases,
        private readonly DatabaseDirectory $directory,
    ) {}

    /**
     * @param  list<string>  $deleteVolumeIds  volumes of the service to delete with it (a database: its data volume)
     */
    public function __invoke(Service $service, array $deleteVolumeIds = [], ?string $actorId = null): void
    {
        match ($service->kind) {
            ServiceKind::Site => $this->sites->delete($service->ref_id, $deleteVolumeIds, $actorId),
            ServiceKind::Database => $this->databases->delete(
                $service->ref_id,
                ($volumeId = $this->directory->find($service->ref_id)?->volumeId) !== null && in_array($volumeId, array_map('strtolower', $deleteVolumeIds), true),
            ),
        };
    }
}
