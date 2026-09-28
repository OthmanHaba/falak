<?php

namespace Kiln\Projects\Application\Actions;

use Illuminate\Support\Facades\DB;
use Kiln\Projects\Domain\Models\Group;
use Kiln\Projects\Domain\Models\Service;

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
