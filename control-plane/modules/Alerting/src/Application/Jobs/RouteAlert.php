<?php

namespace Falak\Alerting\Application\Jobs;

use Falak\Alerting\Application\AlertRouter;
use Falak\Alerting\Contracts\Data\AlertData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
