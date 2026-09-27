<?php

namespace Kiln\Projects\Application\Actions;

use Kiln\Projects\Contracts\ServiceKind;
use Kiln\Projects\Domain\Models\Service;
use Kiln\Projects\Events\ServiceUnlinked;

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

        ServiceUnlinked::dispatch($service->id, $service->organization_id, $service->project_id, $service->environment_id, $kind->value, $service->ref_id);
    }
}
