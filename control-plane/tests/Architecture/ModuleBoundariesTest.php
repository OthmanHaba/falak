<?php

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Falak\Kernel\Modules;

$layers = fn (string $module) => array_map(
    fn (string $layer) => "Falak\\{$module}\\{$layer}",
    Modules::PRIVATE_LAYERS,
);

foreach (Modules::ALL as $module) {
    $others = array_values(array_diff(Modules::ALL, [$module]));

    arch("{$module} only uses other modules' Contracts and Events")
        ->expect("Falak\\{$module}")
        ->not->toUse(array_merge(...array_map($layers, $others)));
}

arch('app/ glue does not reach into module internals')
    ->expect('App')
    ->not->toUse(array_merge(...array_map($layers, Modules::ALL)));

arch('Kernel depends on no module')
    ->expect('Falak\Kernel')
    ->not->toUse(array_map(fn ($m) => "Falak\\{$m}", Modules::ALL));

// Report + wanted stack + config in, decisions out: unit-testable without the framework, the database or an agent.
arch('the machine-check decision engine is pure')
    ->expect('Falak\Servers\Domain\MachineCheck')
    ->not->toUse(['Illuminate', 'Falak\Fleet', 'Falak\Servers\Application', 'Falak\Servers\Infrastructure', 'Falak\Servers\Domain\Models', 'config', 'app', 'now']);

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

// A live-UI broadcast failing (e.g. Reverb down) must never abort the domain work that fired it.
test('synchronous broadcasts are rescued', function () {
    $offenders = [];

    foreach (glob(dirname(__DIR__, 2).'/modules/*/src/Events/*.php') as $file) {
        $class = 'Falak\\'.basename(dirname($file, 3)).'\\Events\\'.basename($file, '.php');

        if (is_subclass_of($class, ShouldBroadcastNow::class)
            && ! is_subclass_of($class, ShouldRescue::class)) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([]);
});
