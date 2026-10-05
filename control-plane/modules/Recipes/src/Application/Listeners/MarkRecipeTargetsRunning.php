<?php

namespace Falak\Recipes\Application\Listeners;

use Falak\Fleet\Events\CommandOutputReceived;
use Falak\Recipes\Application\RunProgress;
use Falak\Recipes\Domain\Enums\TargetStatus;
use Falak\Recipes\Domain\Models\RunTarget;

/**
 * Flips a recipe target to "running" on its command's first live event.
 *
 * Deliberately synchronous: it fires for every ingested event batch of every command, and a single
 * indexed conditional UPDATE is far cheaper than queueing a job per batch. Only the first batch of a
 * recipe command does any further work.
 */
final class MarkRecipeTargetsRunning
{
    public function __construct(private readonly RunProgress $progress) {}

    public function handle(CommandOutputReceived $event): void
    {
        if ($event->events === []) {
            return;
        }

        $updated = RunTarget::query()
            ->where('command_id', $event->commandId)
            ->where('status', TargetStatus::Queued)
            ->update(['status' => TargetStatus::Running, 'started_at' => now(), 'updated_at' => now()]);

        if ($updated === 0) {
            return;
        }

        $target = RunTarget::query()->where('command_id', $event->commandId)->first();

        if ($target) {
            $this->progress->refresh($target->run()->firstOrFail());
        }
    }
}
