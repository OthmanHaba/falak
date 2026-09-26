<?php

namespace Kiln\Telemetry;

use Kiln\Kernel\Support\ModuleServiceProvider;

class TelemetryServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [];
}
