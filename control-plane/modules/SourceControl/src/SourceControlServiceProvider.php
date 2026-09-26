<?php

namespace Kiln\SourceControl;

use Kiln\Kernel\Support\ModuleServiceProvider;

class SourceControlServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [];
}
