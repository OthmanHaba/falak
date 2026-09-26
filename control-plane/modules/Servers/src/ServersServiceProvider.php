<?php

namespace Kiln\Servers;

use Kiln\Kernel\Support\ModuleServiceProvider;

class ServersServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [];
}
