<?php

namespace Kiln\Projects\Application\Actions;

use Kiln\Projects\Domain\Models\Service;

/**
 * Canvas layout of a compose site's group: positions of its compose services (relative to the site card's x/y) and
 * whether the group is collapsed. Stored on the canvas service, never in the Sites module.
 */
final class ArrangeCompose
{
    /**
     * @param  array<string, array{x: int, y: int}>  $children  compose service name => relative position
     */
    public function __invoke(Service $service, array $children = [], ?bool $collapsed = null): Service
    {
        $layout = is_array($service->layout) ? $service->layout : [];

        foreach ($children as $name => $position) {
            $layout['children'][(string) $name] = ['x' => (int) $position['x'], 'y' => (int) $position['y']];
        }

        if ($collapsed !== null) {
            $layout['collapsed'] = $collapsed;
        }

        $service->forceFill(['layout' => $layout])->save();

        return $service;
    }
}
