<?php

use Falak\Kernel\Modules;

// Guards against the architecture boundary rules passing vacuously on missing modules.
test('every registered module exists and its provider is loaded', function (string $module) {
    $provider = "Falak\\{$module}\\{$module}ServiceProvider";

    expect(class_exists($provider))->toBeTrue()
        ->and(app()->getProvider($provider))->toBeInstanceOf($provider);
})->with(Modules::ALL);
