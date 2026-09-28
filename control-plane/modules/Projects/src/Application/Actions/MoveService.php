<?php

namespace Kiln\Projects\Application\Actions;

use Kiln\Projects\Domain\Models\Group;
use Kiln\Projects\Domain\Models\Service;

/**
 * Persist a card position on the canvas, optionally moving it into / out of a group. Positions of grouped services are
 * relative to the group's anchor; a group left without services is dropped.
 */
final class MoveService
{
    /**
     * @param  Group|false|null  $group  a group to join, null to leave the current group, false to keep membership
     */
    public function __invoke(Service $service, int $x, int $y, Group|false|null $group = false): Service
    {
        $previous = $service->group_id;
        $attributes = ['x' => $x, 'y' => $y];

        if ($group !== false) {
            $attributes['group_id'] = $group?->id;
        }

        $service->forceFill($attributes)->save();

        if ($previous !== null && $previous !== $service->group_id && ! Service::query()->where('group_id', $previous)->exists()) {
            Group::query()->whereKey($previous)->delete();
        }

        return $service;
    }

    /**
     * Canvas coordinates of a service (grouped services are stored relative to their group).
     *
     * @return array{x: int, y: int}
     */
    public static function absolute(Service $service): array
    {
        $group = $service->group_id !== null ? $service->group : null;

        return ['x' => $service->x + ($group->x ?? 0), 'y' => $service->y + ($group->y ?? 0)];
    }
}
