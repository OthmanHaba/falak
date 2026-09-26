<?php

namespace Kiln\Deployments\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Deployments\Application\Orchestration\Orchestrator;
use Kiln\Fleet\Events\CommandFailed;
use Kiln\Fleet\Events\CommandFinished;

/**
 * Advances deployments when their agent commands finish. Idempotent: a re-delivered event for a
 * settled step is ignored by the Orchestrator.
 */
final class HandleCommandOutcome implements ShouldQueue
{
    private const TYPES = ['deploy.fetch', 'deploy.prepare', 'deploy.hook', 'deploy.activate', 'deploy.rollback', 'deploy.container.swap', 'proc.restart', 'system.exec'];

    public function __construct(private readonly Orchestrator $orchestrator) {}

    public function handleFinished(CommandFinished $event): void
    {
        if (in_array($event->type, self::TYPES, true)) {
            $this->orchestrator->commandSettled($event->commandId, true, $event->exitCode, $event->result, null);
        }
    }

    public function handleFailed(CommandFailed $event): void
    {
        if (in_array($event->type, self::TYPES, true)) {
            $this->orchestrator->commandSettled($event->commandId, false, $event->exitCode, $event->result, $event->error, $event->status);
        }
    }
}
