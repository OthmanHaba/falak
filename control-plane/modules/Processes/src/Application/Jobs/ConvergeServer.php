<?php

namespace Falak\Processes\Application\Jobs;

use Falak\Processes\Application\ServerConverger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Debounced proc.apply + cron.apply for one server. Unique per server until it starts processing, so a
 * burst of changes queues one job; it compiles at run time and therefore always ships the latest state.
 */
final class ConvergeServer implements ShouldBeUnique, ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public int $uniqueFor = 300;

    public function __construct(public readonly string $serverId) {}

    public function uniqueId(): string
    {
        return $this->serverId;
    }

    public function handle(ServerConverger $converger): void
    {
        $converger->converge($this->serverId);
    }
}
