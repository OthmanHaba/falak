<?php

namespace Kiln\Fleet;

use Kiln\Kernel\Support\ModuleServiceProvider;

class FleetServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [];
}
