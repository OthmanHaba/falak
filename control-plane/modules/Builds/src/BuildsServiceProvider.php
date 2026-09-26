<?php

namespace Kiln\Builds;

use Kiln\Kernel\Support\ModuleServiceProvider;

class BuildsServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [];
}
