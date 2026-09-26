<?php

namespace Kiln\Identity;

use Kiln\Kernel\Support\ModuleServiceProvider;
use Laravel\Fortify\Fortify;

class IdentityServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [];

    public function register(): void
    {
        // Identity owns the auth routes; Fortify is used for its 2FA actions only.
        Fortify::ignoreRoutes();
    }
}
