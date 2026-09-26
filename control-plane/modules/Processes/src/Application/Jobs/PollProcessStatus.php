<?php

namespace Kiln\Processes\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Kiln\Processes\Application\StatusPoller;
use Kiln\Processes\Domain\Models\ServerState;

/**
 * Periodic proc.status on every server running programs (crash-loop detection).
 */
final class PollProcessStatus implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public function handle(StatusPoller $poller): void
    {
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
