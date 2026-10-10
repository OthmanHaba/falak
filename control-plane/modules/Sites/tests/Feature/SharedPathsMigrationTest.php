<?php

use Falak\Identity\Contracts\Role;
use Falak\Sites\Domain\Models\Site;
use Falak\Volumes\Contracts\VolumeMounts;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../Support/helpers.php';

/*
| v0.9.0 → v0.10.0: sites.shared_paths become shared_path volumes before the column goes (cloud.falak.sh has classic
| sites with shared paths).
*/

it('turns existing shared paths into shared_path volumes in their order, then drops the column', function () {
    [, $organization] = actingAsMember(Role::Admin);
    sites_fake_agents();
    sites_fake_source_control();
    $server = sites_server($organization->id);
    $this->post('/sites', sites_input([$server->id], ['name' => 'Shop']))->assertSessionHasNoErrors();
    $site = Site::query()->sole();

    $migration = require base_path('modules/Sites/database/migrations/2026_10_24_600001_drop_shared_paths_from_sites.php');
    $migration->down();
    Volume::query()->delete();
    DB::table('sites_sites')->where('id', $site->id)->update(['shared_paths' => json_encode([
        ['path' => 'storage', 'type' => 'directory'],
        ['path' => '.env', 'type' => 'file'],
        ['path' => '../etc', 'type' => 'directory'],
        ['path' => 'storage', 'type' => 'directory'],
        ['path' => 'public/uploads'],
    ])]);

    $migration->up();

    expect(Schema::hasColumn('sites_sites', 'shared_paths'))->toBeFalse()
        ->and(array_map(fn ($path) => $path->toArray(), app(VolumeMounts::class)->sharedPaths($site->id)))->toBe([
            ['path' => 'storage', 'type' => 'directory'],
            ['path' => '.env', 'type' => 'file'],
            ['path' => 'public/uploads', 'type' => 'directory'],
        ])
        ->and(Volume::query()->where('name', "{$site->slug}/storage")->sole()->host_path)->toBe("/srv/falak/sites/{$site->slug}/shared/storage");
});
