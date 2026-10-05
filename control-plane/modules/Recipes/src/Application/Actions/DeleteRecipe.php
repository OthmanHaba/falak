<?php

namespace Falak\Recipes\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Recipes\Domain\Models\Recipe;

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
