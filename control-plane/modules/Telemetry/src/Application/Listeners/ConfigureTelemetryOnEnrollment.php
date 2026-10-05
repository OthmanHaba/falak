<?php

namespace Falak\Telemetry\Application\Listeners;

use Falak\Fleet\Events\AgentEnrolled;
use Falak\Telemetry\Application\Jobs\DispatchPendingTelemetry;
use Falak\Telemetry\Domain\Models\PendingConfiguration;

/**
 * A (re-)enrolled agent receives the organization's telemetry configuration shortly after
 * enrolling: the enrollment response already carries the OTLP endpoint, and deferring keeps the
 * first commands a fresh agent runs (provisioning, key sync) unobstructed. The per-minute
 * {@see DispatchPendingTelemetry} sweep (or ServerProvisioned,
 * whichever comes first) dispatches it.
 */
final class ConfigureTelemetryOnEnrollment
{
    public function handle(AgentEnrolled $event): void
    {
        if ($event->serverId === null) {
            return;
        }

        PendingConfiguration::query()->updateOrCreate(['server_id' => $event->serverId], [
            'organization_id' => $event->organizationId,
            'due_at' => now()->addSeconds((int) config('telemetry.configure_delay_seconds', 30)),
            'attempts' => 0,
        ]);
    }
}
