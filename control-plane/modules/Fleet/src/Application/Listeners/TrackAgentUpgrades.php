<?php

namespace Falak\Fleet\Application\Listeners;

use Falak\Fleet\Application\AgentUpgradeRollout;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Fleet\Events\AgentFactsReported;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;

/**
 * Moves agent upgrades along: the upgrade command's outcome, then the restarted agent's facts.
 */
final class TrackAgentUpgrades
{
    public function __construct(private readonly AgentUpgradeRollout $rollout) {}

    public function finished(CommandFinished $event): void
    {
        if ($event->type === AgentUpgradeRollout::COMMAND) {
            $this->rollout->installed($event->commandId, $event->result);
        }
    }

    public function failed(CommandFailed $event): void
    {
        if ($event->type === AgentUpgradeRollout::COMMAND) {
            $this->rollout->commandFailed($event->commandId, $event->error ?? "The upgrade command {$event->status}.");
        }
    }

    public function facts(AgentFactsReported $event): void
    {
        if ($agent = Agent::query()->find($event->agentId)) {
            $this->rollout->reported($agent);
        }
    }
}
