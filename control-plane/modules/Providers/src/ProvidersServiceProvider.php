<?php

namespace Kiln\Providers;

use Kiln\Kernel\Support\ModuleServiceProvider;

class ProvidersServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [];
}
