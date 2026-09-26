<?php

namespace Kiln\Insights;

use Kiln\Kernel\Support\ModuleServiceProvider;

class InsightsServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [];
}
