<?php

namespace Kiln\Alerting\Infrastructure;

use Kiln\Alerting\Application\Jobs\RouteAlert;
use Kiln\Alerting\Contracts\Alerts;
use Kiln\Alerting\Contracts\Data\AlertData;

final class QueuedAlerts implements Alerts
{
    public function raise(AlertData $alert): void
    {
        RouteAlert::dispatch($alert)->onQueue((string) config('alerting.queue', 'default'));
    }
}
