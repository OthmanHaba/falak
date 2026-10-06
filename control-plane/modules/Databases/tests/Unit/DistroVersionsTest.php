<?php

use Falak\Databases\Application\EngineInventory;
use Falak\Databases\Domain\Enums\Engine;

function distro_version(Engine $engine, string $id, string $version): array
{
    return app(EngineInventory::class)->detectVersion($engine, ['os' => ['id' => $id, 'version' => $version]]);
}

it('falls back to the version each supported release packages when the agent reports none', function (string $id, string $version, Engine $engine, string $expected) {
    expect(distro_version($engine, $id, $version))->toBe([$expected, 'default']);
})->with([
    'jammy postgresql' => ['ubuntu', '22.04', Engine::PostgreSql, '14'],
    'noble postgresql' => ['ubuntu', '24.04', Engine::PostgreSql, '16'],
    'resolute postgresql' => ['ubuntu', '26.04', Engine::PostgreSql, '18'],
    'resolute mysql' => ['ubuntu', '26.04', Engine::MySql, '8.4'],
    'resolute mariadb' => ['ubuntu', '26.04', Engine::MariaDb, '11.8'],
    'resolute redis' => ['ubuntu', '26.04', Engine::Redis, '8.0'],
    'resolute valkey' => ['ubuntu', '26.04', Engine::Valkey, '9.0'],
    'bookworm postgresql' => ['debian', '12', Engine::PostgreSql, '15'],
]);

it('offers the versions Ubuntu 26.04 installs', function () {
    expect(config('databases.versions.postgresql'))->toContain('18')
        ->and(config('databases.versions.mysql'))->toContain('8.4')
        ->and(config('databases.versions.mariadb'))->toContain('11.8')
        ->and(config('databases.versions.valkey'))->toContain('9.0');
});
