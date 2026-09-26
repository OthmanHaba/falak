<?php

namespace Kiln\Deployments\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Builds\Events\BuildCancelled;
use Kiln\Builds\Events\BuildFailed;
use Kiln\Builds\Events\BuildSucceeded;
use Kiln\Deployments\Application\Orchestration\Orchestrator;

final class HandleBuildEvents implements ShouldQueue
{
    public function __construct(private readonly Orchestrator $orchestrator) {}

    public function succeeded(BuildSucceeded $event): void
    {
        $this->orchestrator->buildSettled($event->buildId, true, null);
    }

    public function failed(BuildFailed $event): void
    {
        $this->orchestrator->buildSettled($event->buildId, false, $event->status === 'timed_out' ? "Build timed out: {$event->error}" : $event->error);
    }

    public function cancelled(BuildCancelled $event): void
    {
        $this->orchestrator->buildSettled($event->buildId, false, null, cancelled: true);
    }
}
