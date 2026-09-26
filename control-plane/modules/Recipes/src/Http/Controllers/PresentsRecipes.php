<?php

namespace Kiln\Recipes\Http\Controllers;

use Kiln\Recipes\Application\RunProgress;
use Kiln\Recipes\Domain\Models\Recipe;
use Kiln\Recipes\Domain\Models\Run;
use Kiln\Recipes\Domain\Models\RunTarget;

trait PresentsRecipes
{
    /**
     * Validation rule for the unix user a script runs as.
     */
    protected const USER_RULE = 'regex:/^[a-z_][a-z0-9_-]{0,31}$/';

    /**
     * @return array<string, mixed>
     */
    protected function recipe(Recipe $recipe): array
    {
        return [
            'id' => $recipe->id,
            'name' => $recipe->name,
            'description' => $recipe->description,
            'script' => $recipe->script,
            'user' => $recipe->user,
            'updated_at' => $recipe->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function runSummary(Run $run): array
    {
        $targets = $run->relationLoaded('targets') ? $run->targets : $run->targets()->get();

        return [
            'id' => $run->id,
            'recipe_id' => $run->recipe_id,
            'builtin' => $run->builtin,
            'recipe_name' => $run->recipe_name,
            'user' => $run->user,
            'status' => $run->status->value,
            'servers' => $targets->count(),
            'succeeded' => $targets->filter(fn (RunTarget $t) => $t->status->value === 'succeeded')->count(),
            'failed' => $targets->filter(fn (RunTarget $t) => in_array($t->status->value, ['failed', 'unavailable'], true))->count(),
            'created_at' => $run->created_at->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function runTarget(RunTarget $target): array
    {
        return [
            ...RunProgress::target($target),
            'server_name' => $target->server_name,
            'started_at' => $target->started_at?->toIso8601String(),
            'finished_at' => $target->finished_at?->toIso8601String(),
        ];
    }
}
