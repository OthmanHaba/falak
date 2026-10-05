<?php

namespace Falak\Alerting\Application\Listeners;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Alerts;
use Illuminate\Contracts\Container\Container;

/**
 * Wildcard listener: any event implementing Alertable is routed, so emitting modules never
 * depend on Alerting internals (and Alerting never imports them). Cheap instanceof check only;
 * routing happens in the queued RouteAlert job.
 */
final class RouteAlertableEvents
{
    public function __construct(private readonly Container $container) {}

    /**
     * @param  array<int, mixed>  $payload
     */
    public function handle(string $eventName, array $payload): void
    {
        $event = $payload[0] ?? null;

        if ($event instanceof Alertable) {
            $this->container->make(Alerts::class)->raise($event->toAlert());
        }
    }
}
