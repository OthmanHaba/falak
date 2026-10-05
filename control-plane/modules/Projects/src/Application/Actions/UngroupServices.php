<?php

namespace Falak\Projects\Application\Actions;

use Falak\Projects\Domain\Models\Group;
use Falak\Projects\Domain\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * Dissolve a canvas group: its services keep their place on screen (positions become absolute again).
 */
final class UngroupServices
{
    public function __invoke(Group $group): void
    {
        DB::transaction(function () use ($group) {
            foreach ($group->services()->get() as $service) {
                /** @var Service $service */
                $service->forceFill(['group_id' => null, 'x' => $group->x + $service->x, 'y' => $group->y + $service->y])->save();
            }

            $group->delete();
        });
    }
}
