<?php

namespace Kiln\Processes\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Kiln\Processes\Application\OctaneRoutes;
use Kiln\Processes\Application\ServerConverger;
use Kiln\Processes\Application\StatusPoller;
use Kiln\Processes\Domain\Enums\OctaneRouteStatus;
use Kiln\Processes\Domain\Models\OctaneRoute;
use Kiln\Processes\Domain\Models\ServerState;

/**
 * Periodic proc.status on every server running programs (crash-loop detection), and Octane probes.
 */
final class PollProcessStatus implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public function handle(StatusPoller $poller, OctaneRoutes $octane, ServerConverger $converger): void
    {
        // Octane that never answered (crash, first deploy pending …) is re-probed; stuck drains are stopped.
        $converger->schedule(...$octane->expireDrains());

        foreach (OctaneRoute::query()->where('status', OctaneRouteStatus::Draining)->distinct()->pluck('server_id') as $serverId) {
            if ($octane->drained((string) $serverId)) {
                $converger->schedule((string) $serverId);
            }
        }

        foreach (OctaneRoute::query()->whereIn('status', [OctaneRouteStatus::Starting, OctaneRouteStatus::Failed])->distinct()->pluck('server_id') as $serverId) {
            $octane->probeServer((string) $serverId);
        }

        ServerState::query()->whereNotNull('applied_programs')->orderBy('server_id')->chunkById(200, function ($states) use ($poller) {
            foreach ($states as $state) {
                /** @var ServerState $state */
                if (($state->applied_programs ?? []) !== []) {
                    $poller->request($state->server_id);
                }
            }
        }, 'server_id');
    }
}
