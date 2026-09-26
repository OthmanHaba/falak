<?php

namespace Kiln\Alerting;

use Kiln\Kernel\Support\ModuleServiceProvider;

class AlertingServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [];
}
