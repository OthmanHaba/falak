<?php

namespace Kiln\Recipes\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Every server of a recipe run reached a final state.
 */
final class RecipeRunFinished
{
    use Dispatchable;

    /**
     * @param  string  $status  succeeded | failed | partial
     */
    public function __construct(
        public string $runId,
        public string $organizationId,
        public ?string $recipeId,
        public string $recipeName,
        public string $status,
        public int $succeeded,
        public int $failed,
        public int $total,
    ) {}
}
