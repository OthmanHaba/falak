<?php

namespace Falak\Recipes\Application;

use Falak\Recipes\Domain\Enums\RunStatus;
use Falak\Recipes\Domain\Enums\TargetStatus;
use Falak\Recipes\Domain\Models\Run;
use Falak\Recipes\Domain\Models\RunTarget;
use Falak\Recipes\Events\RecipeRunFinished;
use Falak\Recipes\Events\RecipeRunUpdated;

/**
 * Rolls target states up into the run status and announces changes.
 */
final class RunProgress
{
    public function refresh(Run $run): void
    {
        $targets = $run->targets()->get();

        $finished = $targets->filter(fn (RunTarget $t) => $t->status->isFinished());
        $succeeded = $targets->where('status', TargetStatus::Succeeded)->count();
        $total = $targets->count();

        $status = match (true) {
            $total === 0 => RunStatus::Failed,
            $finished->count() < $total => $targets->contains(fn (RunTarget $t) => $t->status !== TargetStatus::Queued) ? RunStatus::Running : RunStatus::Pending,
            $succeeded === $total => RunStatus::Succeeded,
            $succeeded === 0 => RunStatus::Failed,
            default => RunStatus::Partial,
        };

        $firstFinish = false;

        if ($status->isFinished()) {
            // Conditional update: exactly one concurrent listener wins the transition to finished.
            $firstFinish = Run::query()->whereKey($run->id)->whereNull('finished_at')->update([
                'status' => $status,
                'started_at' => $run->started_at ?? now(),
                'finished_at' => now(),
                'updated_at' => now(),
            ]) === 1;
        } else {
            Run::query()->whereKey($run->id)->whereNull('finished_at')->update([
                'status' => $status,
                'started_at' => $status !== RunStatus::Pending ? ($run->started_at ?? now()) : null,
                'updated_at' => now(),
            ]);
        }

        $run->refresh();

        RecipeRunUpdated::dispatch($run->id, $run->status->value, $targets->map(fn (RunTarget $t) => self::target($t))->values()->all());

        if ($firstFinish) {
            RecipeRunFinished::dispatch($run->id, $run->organization_id, $run->recipe_id, $run->recipe_name, $status->value, $succeeded, $total - $succeeded, $total);
        }
    }

    /**
     * @return array{id: string, server_id: string, status: string, command_id: ?string, exit_code: ?int, error: ?string, duration_ms: ?int}
     */
    public static function target(RunTarget $target): array
    {
        return [
            'id' => $target->id,
            'server_id' => $target->server_id,
            'status' => $target->status->value,
            'command_id' => $target->command_id,
            'exit_code' => $target->exit_code,
            'error' => $target->error,
            'duration_ms' => $target->duration_ms,
        ];
    }
}
