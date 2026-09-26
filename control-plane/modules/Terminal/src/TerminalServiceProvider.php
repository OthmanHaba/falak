<?php

namespace Kiln\Terminal;

use Kiln\Kernel\Support\ModuleServiceProvider;

class TerminalServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [];
}
