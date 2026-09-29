<?php

namespace Kiln\Projects\Application\Actions;

use Kiln\Databases\Contracts\DatabaseProvisioner;
use Kiln\Projects\Contracts\ServiceKind;
use Kiln\Projects\Domain\Models\Service;
use Kiln\Sites\Contracts\SiteFactory;

/**
 * The panel's Delete: deletes the site / database through its owning module. The card leaves the canvas when
 * that module reports the deletion (SiteDeleted right away; DatabaseDeleted once the agent dropped it).
 */
final class DeleteService
{
    public function __construct(
        private readonly SiteFactory $sites,
        private readonly DatabaseProvisioner $databases,
    ) {}

    public function __invoke(Service $service, bool $deleteVolumes = false): void
    {
        match ($service->kind) {
            ServiceKind::Site => $this->sites->delete($service->ref_id, $deleteVolumes),
            ServiceKind::Database => $this->databases->delete($service->ref_id),
        };
    }
}
