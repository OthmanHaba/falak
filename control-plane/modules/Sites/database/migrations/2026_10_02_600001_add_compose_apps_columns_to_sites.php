<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compose apps from a repository (docs/plans/COMPOSE_APPS.md, lane contract): several compose files (-f order),
 * active profiles, a decision per service (keep / Kiln database / own Kiln site) and the user's choices about
 * Kiln's adjustments. `compose_file` is kept (the first file) and copied into `compose_files`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites_sites', function (Blueprint $table) {
            $table->json('compose_files')->nullable()->after('compose_source');
            $table->json('compose_profiles')->nullable()->after('compose_files');
            $table->json('compose_services')->nullable()->after('compose_profiles');
            $table->json('compose_adjustments')->nullable()->after('compose_services');
        });

        DB::table('sites_sites')->whereNotNull('compose_file')->where('compose_file', '!=', '')->orderBy('id')
            ->each(fn (object $site) => DB::table('sites_sites')->where('id', $site->id)->update(['compose_files' => json_encode([$site->compose_file])]));
    }

    public function down(): void
    {
        Schema::table('sites_sites', function (Blueprint $table) {
            $table->dropColumn(['compose_files', 'compose_profiles', 'compose_services', 'compose_adjustments']);
        });
    }
};
