<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compose apps from a git repository (docs/plans/COMPOSE_APPS.md, contract between lanes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites_sites', function (Blueprint $table) {
            // Repo compose files in `-f` order (replaces compose_file, copied below).
            $table->json('compose_files')->nullable()->after('compose_file');
            $table->json('compose_profiles')->nullable()->after('compose_files');
            // {<service>: {"mode": "keep"|"database"|"site", "database_id"?, "site_id"?}}; absent service = keep.
            $table->json('compose_services')->nullable()->after('compose_profiles');
        });

        DB::table('sites_sites')->whereNotNull('compose_file')->where('compose_file', '!=', '')->orderBy('id')->each(function (object $site) {
            DB::table('sites_sites')->where('id', $site->id)->update(['compose_files' => json_encode([$site->compose_file])]);
        });
    }

    public function down(): void
    {
        Schema::table('sites_sites', function (Blueprint $table) {
            $table->dropColumn(['compose_files', 'compose_profiles', 'compose_services']);
        });
    }
};
