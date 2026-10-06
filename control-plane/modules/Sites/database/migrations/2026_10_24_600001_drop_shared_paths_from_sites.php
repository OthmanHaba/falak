<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared paths are volumes now (Volumes, kind shared_path, attached to the site). No import: v0.10.0 starts fresh.
 */
return new class extends Migration
{
    public function up(): void
    {
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
