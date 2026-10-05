<?php

namespace Falak\Recipes\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Recipes\Application\Actions\CopyBuiltinRecipe;
use Falak\Recipes\Application\Actions\DeleteRecipe;
use Falak\Recipes\Application\Actions\SaveRecipe;
use Falak\Recipes\Domain\BuiltinRecipe;
use Falak\Recipes\Domain\Models\Recipe;
use Falak\Recipes\Domain\Models\Run;
use Falak\Recipes\Infrastructure\BuiltinRecipes;

final class RecipeController extends Controller
{
    use PresentsRecipes;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly BuiltinRecipes $builtins,
    ) {}

    public function index(Request $request): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'recipes.view');

        return Inertia::render('Recipes/Index', [
            'recipes' => Recipe::query()->where('organization_id', $organizationId)->orderBy('name')->get()->map(fn (Recipe $r) => $this->recipe($r))->values(),
            'builtins' => array_values(array_map(fn (BuiltinRecipe $r) => $r->toArray(), $this->builtins->all())),
            'recentRuns' => Run::query()->with('targets')->where('organization_id', $organizationId)->latest()->orderByDesc('id')->limit(5)->get()->map(fn (Run $run) => $this->runSummary($run))->values(),
            'can' => [
                'manage' => $this->access->can($request->user(), $organizationId, 'recipes.manage'),
                'run' => $this->access->can($request->user(), $organizationId, 'recipes.run'),
            ],
        ]);
    }

    public function store(Request $request, SaveRecipe $save): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'recipes.manage');

        $save($organizationId, $this->validated($request, $organizationId), null, $request->user()?->getAuthIdentifier());

        return back();
    }

    public function update(Request $request, Recipe $recipe, SaveRecipe $save): RedirectResponse
    {
        $this->authorize('update', $recipe);

        $save($recipe->organization_id, $this->validated($request, $recipe->organization_id, $recipe), $recipe);

        return back();
    }

    public function destroy(Recipe $recipe, DeleteRecipe $delete): RedirectResponse
    {
        $this->authorize('delete', $recipe);

        $delete($recipe);

        return to_route('recipes.index');
    }

    public function copy(Request $request, string $key, CopyBuiltinRecipe $copy): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'recipes.manage');

        $builtin = $this->builtins->find($key) ?? abort(404);
        $copy($organizationId, $builtin, $request->user()?->getAuthIdentifier());

        return back();
    }

    /**
     * @return array{name: string, description: ?string, script: string, user: string}
     */
    private function validated(Request $request, string $organizationId, ?Recipe $recipe = null): array
    {
        /** @var array{name: string, description: ?string, script: string, user: string} */
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('recipes_recipes')->where('organization_id', $organizationId)->ignore($recipe?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'script' => ['required', 'string', 'max:'.(int) config('recipes.max_script_bytes', 65536), 'not_regex:/^\s*$/'],
            'user' => ['required', 'string', self::USER_RULE],
        ]);
    }
}
