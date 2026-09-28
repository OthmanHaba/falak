<?php

use Illuminate\Database\Migrations\Migration;
use Kiln\Sites\Application\OctanePorts;
use Kiln\Sites\Domain\Models\Site;

/**
 * Octane ports used to be derived (app_port, else 8000 + crc32(site id) % 1000) and could collide silently.
 * Persist a server + unique port for every site that has Octane on (keeping the old port when it is free).
 */
return new class extends Migration
{
    public function up(): void
    {
        $ports = app(OctanePorts::class);

        Site::query()->with('targets')->orderBy('created_at')->orderBy('id')->each(function (Site $site) use ($ports) {
            if (! $site->laravel->octane || $site->laravel->octanePort !== null || ! $site->runtime->isPhp()) {
                return;
            }

            $legacy = $site->app_port ?? (8000 + (crc32($site->id) % 1000));
            $site->forceFill(['laravel' => $ports->resolve($site, $site->laravel->with(octanePort: $legacy))])->save();
        });
    }

    public function down(): void {}
};
