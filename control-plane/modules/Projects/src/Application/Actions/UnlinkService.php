<?php

namespace Falak\Projects\Application\Actions;

use Falak\Projects\Contracts\ServiceKind;
use Falak\Projects\Domain\Models\Group;
use Falak\Projects\Domain\Models\Service;
use Falak\Projects\Events\ServiceUnlinked;

/**
 * Remove a deleted site / database from its environment.
 */
final class UnlinkService
{
    public function __invoke(ServiceKind $kind, string $refId): void
    {
        $service = Service::query()->where('kind', $kind)->where('ref_id', strtolower($refId))->first();

        if ($service === null) {
            return;
        }

        $service->delete();

        // A canvas group left without services disappears with its last card.
        if ($service->group_id !== null && ! Service::query()->where('group_id', $service->group_id)->exists()) {
            Group::query()->whereKey($service->group_id)->delete();
        }

        ServiceUnlinked::dispatch($service->id, $service->organization_id, $service->project_id, $service->environment_id, $kind->value, $service->ref_id);
    }
}
