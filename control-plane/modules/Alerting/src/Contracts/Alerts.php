<?php

namespace Kiln\Alerting\Contracts;

use Kiln\Alerting\Contracts\Data\AlertData;

/**
 * Raise an alert directly (the event-based path is {@see Alertable}). Routing, quiet hours,
 * deduplication, rate limiting and delivery are asynchronous.
 */
interface Alerts
{
    public function raise(AlertData $alert): void;
}
