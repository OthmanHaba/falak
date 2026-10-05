<?php

use App\Providers\AppServiceProvider;
use Falak\Kernel\Modules;

return [
    AppServiceProvider::class,
    ...Modules::providers(),
];
