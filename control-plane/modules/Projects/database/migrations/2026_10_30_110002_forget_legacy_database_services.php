<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.10.0 recreated the Databases tables (2026_10_30_110001): canvas services of databases that no longer exist go.
 * References to them (`${{ name.KEY }}`) then fail to resolve with "no service named …" until they are changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('projects_services') || ! Schema::hasTable('databases_databases')) {
            return;
        }

        DB::table('projects_services')->where('kind', 'database')
            ->whereNotIn('ref_id', DB::table('databases_databases')->select('id'))
            ->delete();
    }

    public function down(): void {}
};
