<?php

namespace Kiln\Recipes\Application\Actions;

use Kiln\Identity\Contracts\AuditLog;
use Kiln\Recipes\Domain\Models\Recipe;

final class SaveRecipe
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * Create a recipe in $organizationId, or update $recipe.
     *
     * @param  array{name: string, description?: ?string, script: string, user: string}  $data
     */
    public function __invoke(string $organizationId, array $data, ?Recipe $recipe = null, ?string $actorId = null): Recipe
    {
        $creating = $recipe === null;
        $recipe ??= new Recipe(['organization_id' => $organizationId, 'created_by' => $actorId]);

        $recipe->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            // Normalise line endings pasted from Windows editors; bash chokes on \r.
            'script' => str_replace("\r\n", "\n", $data['script']),
            'user' => $data['user'],
        ])->save();

        $this->audit->record(
            $creating ? 'recipe.created' : 'recipe.updated',
            'recipe',
            $recipe->id,
            ['name' => $recipe->name, 'user' => $recipe->user, 'script_sha256' => hash('sha256', $recipe->script)],
            $organizationId,
        );

        return $recipe;
    }
}
