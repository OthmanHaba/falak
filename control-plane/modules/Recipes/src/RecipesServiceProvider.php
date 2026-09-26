<?php

namespace Kiln\Recipes;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Kiln\Fleet\Events\CommandFailed;
use Kiln\Fleet\Events\CommandFinished;
use Kiln\Fleet\Events\CommandOutputReceived;
use Kiln\Identity\Contracts\PermissionRegistry;
use Kiln\Identity\Contracts\Role;
use Kiln\Kernel\Support\ModuleServiceProvider;
use Kiln\Recipes\Application\Listeners\MarkRecipeTargetsRunning;
use Kiln\Recipes\Application\Listeners\SettleRecipeTargets;
use Kiln\Recipes\Domain\Models\Recipe;
use Kiln\Recipes\Domain\Models\Run;
use Kiln\Recipes\Domain\Policies\RecipePolicy;
use Kiln\Recipes\Domain\Policies\RunPolicy;
use Kiln\Recipes\Http\Channels\RunChannel;
use Kiln\Recipes\Infrastructure\BuiltinRecipes;

class RecipesServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        BuiltinRecipes::class => BuiltinRecipes::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/recipes.php', 'recipes');
    }

    protected function bootModule(): void
    {
        Gate::policy(Recipe::class, RecipePolicy::class);
        Gate::policy(Run::class, RunPolicy::class);

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('recipes.view', [Role::Admin, Role::Developer, Role::Viewer], 'View recipes and run history', 'recipes');
        $registry->register('recipes.manage', [Role::Admin, Role::Developer], 'Create, edit and delete recipes', 'recipes');
        $registry->register('recipes.run', [Role::Admin], 'Run recipes (arbitrary scripts, possibly as root) on servers', 'recipes');

        Event::listen(CommandFinished::class, [SettleRecipeTargets::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [SettleRecipeTargets::class, 'handleFailed']);
        Event::listen(CommandOutputReceived::class, MarkRecipeTargetsRunning::class);

        Broadcast::channel(RunChannel::NAME, RunChannel::class);
    }
}
