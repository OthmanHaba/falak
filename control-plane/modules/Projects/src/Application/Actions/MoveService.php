<?php

namespace Kiln\Projects\Application\Actions;

use Kiln\Projects\Domain\Models\Service;

/**
 * Persist a card position on the canvas.
 */
final class MoveService
{
    public function __invoke(Service $service, int $x, int $y): Service
    {
        $service->forceFill(['x' => $x, 'y' => $y])->save();

        return $service;
    }
}
