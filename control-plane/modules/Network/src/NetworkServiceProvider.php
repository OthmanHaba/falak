<?php

namespace Kiln\Network;

use Kiln\Kernel\Support\ModuleServiceProvider;

class NetworkServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [];
}
