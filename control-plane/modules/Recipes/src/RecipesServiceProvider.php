<?php

namespace Kiln\Recipes;

use Kiln\Kernel\Support\ModuleServiceProvider;

class RecipesServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [];
}
