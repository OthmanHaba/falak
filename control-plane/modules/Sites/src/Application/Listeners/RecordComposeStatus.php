<?php

namespace Kiln\Sites\Application\Listeners;

use Kiln\Fleet\Events\CommandFailed;
use Kiln\Fleet\Events\CommandFinished;
use Kiln\Sites\Domain\Models\ComposeState;

/**
 * Stores docker.compose.ps results for the Services tab (and clears the pending marker on failure).
 */
final class RecordComposeStatus
{
    public function handleFinished(CommandFinished $event): void
    {
        if ($event->type !== 'docker.compose.ps') {
            return;
        }

        $state = ComposeState::query()->where('command_id', $event->commandId)->first();

        if ($state === null) {
            return;
        }

        $services = is_array($event->result['services'] ?? null) ? array_values(array_filter($event->result['services'], 'is_array')) : [];
        $state->forceFill(['services' => $services, 'command_id' => null, 'reported_at' => now()])->save();
    }

    public function handleFailed(CommandFailed $event): void
    {
        if ($event->type === 'docker.compose.ps') {
            ComposeState::query()->where('command_id', $event->commandId)->update(['command_id' => null]);
        }
    }
}
