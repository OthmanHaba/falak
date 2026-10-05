<?php

namespace Falak\Recipes\Application\Actions;

use Falak\Recipes\Domain\BuiltinRecipe;
use Falak\Recipes\Domain\Models\Recipe;

/**
 * Copies a built-in recipe into the organization so it can be customised.
 */
final class CopyBuiltinRecipe
{
    public function __construct(private readonly SaveRecipe $save) {}

    public function __invoke(string $organizationId, BuiltinRecipe $builtin, ?string $actorId = null): Recipe
    {
        $name = $builtin->name;

        for ($i = 2; Recipe::query()->where('organization_id', $organizationId)->where('name', $name)->exists(); $i++) {
            $name = "{$builtin->name} ({$i})";
        }

        return ($this->save)($organizationId, [
            'name' => $name,
            'description' => $builtin->description,
            'script' => $builtin->script,
            'user' => $builtin->user,
        ], null, $actorId);
    }
}
