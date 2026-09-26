<?php

namespace Kiln\Processes;

use Kiln\Kernel\Support\ModuleServiceProvider;

class ProcessesServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [];
}
