<?php

namespace Falak\Recipes;

use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Fleet\Events\CommandOutputReceived;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Recipes\Application\Listeners\MarkRecipeTargetsRunning;
use Falak\Recipes\Application\Listeners\SettleRecipeTargets;
use Falak\Recipes\Domain\Models\Recipe;
use Falak\Recipes\Domain\Models\Run;
use Falak\Recipes\Domain\Policies\RecipePolicy;
use Falak\Recipes\Domain\Policies\RunPolicy;
use Falak\Recipes\Http\Channels\RunChannel;
use Falak\Recipes\Infrastructure\BuiltinRecipes;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

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
