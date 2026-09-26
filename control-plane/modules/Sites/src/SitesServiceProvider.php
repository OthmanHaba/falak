<?php

namespace Kiln\Sites;

use Kiln\Kernel\Support\ModuleServiceProvider;

class SitesServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [];
}
