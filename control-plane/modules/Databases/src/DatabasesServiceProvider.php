<?php

namespace Kiln\Databases;

use Kiln\Kernel\Support\ModuleServiceProvider;

class DatabasesServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [];
}
