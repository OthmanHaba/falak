<?php

namespace Falak\Recovery\Application\Jobs;

use Falak\Recovery\Application\ServerRecoveryRunner;
use Falak\Recovery\Domain\Models\ServerRecovery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Every minute (scheduler): running server recoveries move on as their databases, volumes and deployments finish.
 */
final class AdvanceServerRecoveries implements ShouldQueue
{
    use Queueable;

    public function handle(ServerRecoveryRunner $runner): void
    {
        ServerRecovery::query()->where('status', 'running')->orderBy('created_at')->each(fn (ServerRecovery $recovery) => $runner->advance($recovery));
    }
}
