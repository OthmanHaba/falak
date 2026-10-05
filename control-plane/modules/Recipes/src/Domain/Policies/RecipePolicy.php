<?php

namespace Falak\Recipes\Domain\Policies;

use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Recipes\Domain\Models\Recipe;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;

final class RecipePolicy
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function view(Authenticatable $user, Recipe $recipe): Response
    {
        return $this->check($user, $recipe, 'recipes.view');
    }

    public function update(Authenticatable $user, Recipe $recipe): Response
    {
        return $this->check($user, $recipe, 'recipes.manage');
    }

    public function delete(Authenticatable $user, Recipe $recipe): Response
    {
        return $this->check($user, $recipe, 'recipes.manage');
    }

    public function run(Authenticatable $user, Recipe $recipe): Response
    {
        return $this->check($user, $recipe, 'recipes.run');
    }

    private function check(Authenticatable $user, Recipe $recipe, string $permission): Response
    {
        if (! $this->access->can($user, $recipe->organization_id, 'recipes.view')) {
            // Do not reveal recipes of other organizations.
            return Response::denyAsNotFound();
        }

        return $this->access->can($user, $recipe->organization_id, $permission) ? Response::allow() : Response::deny();
    }
}
