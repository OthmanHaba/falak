<?php

namespace Kiln\Edge;

use Kiln\Kernel\Support\ModuleServiceProvider;

class EdgeServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [];
}
