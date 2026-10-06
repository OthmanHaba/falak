<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Shared paths are volumes now (Volumes, kind shared_path, attached to the site): each site's shared_paths become
 * volumes with their attachment, in their order, before the column goes. Same rows as Volumes' SyncSharedPaths writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $root = rtrim((string) config('sites.root', '/srv/falak/sites'), '/');
        $now = now();

        DB::table('sites_sites')->select(['id', 'organization_id', 'slug', 'shared_paths', 'created_by'])->orderBy('id')
            ->chunk(200, function ($sites) use ($root, $now) {
                foreach ($sites as $site) {
                    $position = 0;
                    $seen = [];

                    foreach ((array) json_decode((string) $site->shared_paths, true) as $path) {
                        $relative = is_array($path) ? trim((string) ($path['path'] ?? ''), '/') : '';

                        if ($relative === '' || isset($seen[$relative]) || preg_match('#(^|/)\.\.?(/|$)#', $relative) === 1) {
                            continue;
                        }

                        $seen[$relative] = true;
                        $volumeId = strtolower((string) Str::ulid());

                        DB::table('volumes_volumes')->insert([
                            'id' => $volumeId,
                            'organization_id' => $site->organization_id,
                            'server_id' => null,
                            'name' => mb_substr("{$site->slug}/{$relative}", 0, 128),
                            'kind' => 'shared_path',
                            'host_path' => "{$root}/{$site->slug}/shared/{$relative}",
                            'options' => json_encode(['type' => ($path['type'] ?? 'directory') === 'file' ? 'file' : 'directory', 'position' => $position++]),
                            'protected' => false,
                            'status' => 'active',
                            'created_by' => $site->created_by,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                        DB::table('volumes_attachments')->insert([
                            'id' => strtolower((string) Str::ulid()),
                            'volume_id' => $volumeId,
                            'attachable_type' => 'site',
                            'attachable_id' => $site->id,
                            'mount_path' => $relative,
                            'read_only' => false,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            });

        Schema::table('sites_sites', function (Blueprint $table) {
            $table->dropColumn('shared_paths');
        });
    }

    public function down(): void
    {
        Schema::table('sites_sites', function (Blueprint $table) {
            $table->json('shared_paths')->nullable();
        });
    }
};
