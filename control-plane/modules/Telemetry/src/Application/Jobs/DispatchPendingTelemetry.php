<?php

namespace Falak\Telemetry\Application\Jobs;

use Falak\Telemetry\Contracts\TelemetryConfigurator;
use Falak\Telemetry\Domain\Models\PendingConfiguration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Sends telemetry.configure to servers whose agent enrolled at least `configure_delay_seconds` ago.
 * Servers without an agent (yet) are retried a few times, then dropped.
 */
final class DispatchPendingTelemetry implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public const MAX_ATTEMPTS = 10;

    public function handle(TelemetryConfigurator $configurator): void
    {
        PendingConfiguration::query()
            ->where('due_at', '<=', now())
            ->orderBy('due_at')
            ->limit(500)
            ->get()
            ->each(function (PendingConfiguration $pending) use ($configurator) {
                if ($configurator->reconfigure($pending->server_id) !== null || $pending->attempts + 1 >= self::MAX_ATTEMPTS) {
                    $pending->delete();

                    return;
                }

                $pending->forceFill(['attempts' => $pending->attempts + 1, 'due_at' => now()->addMinute()])->save();
            });
    }
}
