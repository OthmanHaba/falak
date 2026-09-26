<?php

use Kiln\Kernel\Modules;

$layers = fn (string $module) => array_map(
    fn (string $layer) => "Kiln\\{$module}\\{$layer}",
    Modules::PRIVATE_LAYERS,
);

foreach (Modules::ALL as $module) {
    $others = array_values(array_diff(Modules::ALL, [$module]));

    arch("{$module} only uses other modules' Contracts and Events")
        ->expect("Kiln\\{$module}")
        ->not->toUse(array_merge(...array_map($layers, $others)));
}

arch('app/ glue does not reach into module internals')
    ->expect('App')
    ->not->toUse(array_merge(...array_map($layers, Modules::ALL)));

arch('Kernel depends on no module')
    ->expect('Kiln\Kernel')
    ->not->toUse(array_map(fn ($m) => "Kiln\\{$m}", Modules::ALL));

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();
