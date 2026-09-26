<?php

namespace Kiln\Deployments;

use Kiln\Kernel\Support\ModuleServiceProvider;

class DeploymentsServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [];
}
