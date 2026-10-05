<?php

namespace Falak\Alerting\Application\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Falak\Alerting\Application\AlertRouter;
use Falak\Alerting\Contracts\Data\AlertData;

final class RouteAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly AlertData $alert) {}

    public function handle(AlertRouter $router): void
    {
        $router->route($this->alert);
    }
}
