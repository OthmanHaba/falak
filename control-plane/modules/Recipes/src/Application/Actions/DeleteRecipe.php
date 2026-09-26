<?php

namespace Kiln\Recipes\Application\Actions;

use Kiln\Identity\Contracts\AuditLog;
use Kiln\Recipes\Domain\Models\Recipe;

/**
 * Deletes a recipe. Run history keeps its script snapshot.
 */
final class DeleteRecipe
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(Recipe $recipe): void
    {
        $recipe->delete();

        $this->audit->record('recipe.deleted', 'recipe', $recipe->id, ['name' => $recipe->name], $recipe->organization_id);
    }
}
