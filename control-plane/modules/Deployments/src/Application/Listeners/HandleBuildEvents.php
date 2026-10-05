<?php

namespace Falak\Deployments\Application\Listeners;

use Falak\Builds\Events\BuildCancelled;
use Falak\Builds\Events\BuildFailed;
use Falak\Builds\Events\BuildSucceeded;
use Falak\Deployments\Application\Orchestration\Orchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;

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
