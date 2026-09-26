<?php

use App\Providers\AppServiceProvider;
use Kiln\Kernel\Modules;

return [
    AppServiceProvider::class,
    ...Modules::providers(),
];
