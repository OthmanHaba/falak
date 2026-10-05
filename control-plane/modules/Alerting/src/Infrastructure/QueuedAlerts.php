<?php

namespace Falak\Alerting\Infrastructure;

use Falak\Alerting\Application\Jobs\RouteAlert;
use Falak\Alerting\Contracts\Alerts;
use Falak\Alerting\Contracts\Data\AlertData;

final class QueuedAlerts implements Alerts
{
    public function raise(AlertData $alert): void
    {
        RouteAlert::dispatch($alert)->onQueue((string) config('alerting.queue', 'default'));
    }
}
