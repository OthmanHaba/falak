<?php

use App\Providers\AppServiceProvider;
use Falak\Kernel\KernelServiceProvider;
use Falak\Kernel\Modules;

return [
    AppServiceProvider::class,
    KernelServiceProvider::class,
    ...Modules::providers(),
];
