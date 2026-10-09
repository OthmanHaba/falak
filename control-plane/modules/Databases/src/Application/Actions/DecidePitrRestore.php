<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Enums\RestoreStatus;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\Restore;
use Falak\Identity\Contracts\AuditLog;
use Illuminate\Validation\ValidationException;

/**
 * What becomes of a point-in-time restore's read-only copy:
 *
 *  - swap: it takes over the instance it was restored from (name, DNS name, host port, databases, users, schedules,
 *    PITR settings); the old one is stopped and kept, with its volume, until someone deletes it;
 *  - keep: it becomes a database of its own, next to the old one (on the canvas);
 *  - discard: its container and volume are deleted.
 *
 * Swap and keep first make the copy writable (db.pitr.promote, which also stops the old one for a swap); the rest
 * happens when the agent confirmed (SettlePitr::promoted).
 */
final class DecidePitrRestore
{
    public const DECISIONS = ['swap', 'keep', 'discard'];

    public function __construct(
        private readonly AgentCommands $commands,
        private readonly SettlePitr $settle,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Restore $restore, string $decision, ?string $actorId = null): Restore
    {
        if ($restore->type !== Restore::PITR || $restore->status !== RestoreStatus::AwaitingDecision) {
            throw ValidationException::withMessages(['decision' => 'This restore is not waiting for a decision.']);
        }

        if (! in_array($decision, self::DECISIONS, true)) {
            throw ValidationException::withMessages(['decision' => 'Choose swap, keep or discard.']);
        }

        $copy = DatabaseInstance::query()->find($restore->restored_instance_id);
        $source = DatabaseInstance::query()->find($restore->source_instance_id);

        if ($copy === null || $copy->status !== InstanceStatus::Inspecting) {
            throw ValidationException::withMessages(['decision' => 'The restored instance is gone.']);
        }

        if ($decision === 'swap' && ($source === null || ! in_array($source->status, [InstanceStatus::Active, InstanceStatus::Failed], true))) {
            throw ValidationException::withMessages(['decision' => 'The original database is '.($source?->status->value ?? 'gone').': it can\'t be swapped out.']);
        }

        if ($decision === 'discard') {
            $this->settle->discard($copy);
            $restore->forceFill(['status' => RestoreStatus::Discarded, 'decision' => 'discard', 'decided_at' => now(), 'decided_by' => $actorId])->save();
        } else {
            $handle = $this->commands->dispatch($copy->server_id, 'db.pitr.promote', array_filter([
                'instance' => $copy->id,
                'engine' => $copy->engine->protocol(),
                'stop' => $decision === 'swap' ? $source?->id : null,
                'inspection_user' => RestoreToTime::INSPECTION_USER,
            ]), (int) config('databases.timeouts.ddl', 300), "db.pitr.promote:{$restore->id}:{$decision}", 'decision');

            $restore->forceFill(['status' => RestoreStatus::Running, 'decision' => $decision, 'decided_at' => now(), 'decided_by' => $actorId, 'command_id' => $handle->id])->save();
        }

        $this->audit->record('databases.pitr_restore_decision', 'database_instance', (string) $restore->source_instance_id, [
            'restore_id' => $restore->id,
            'decision' => $decision,
            'new_instance_id' => $copy->id,
        ], $restore->organization_id);

        return $restore;
    }
}
