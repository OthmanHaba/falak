<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The merged compose project of a repository stack (its files read from git), kept so the canvas and other read
 * models can show its services without reading the repository. Refreshed on create, on Settings → Compose saves and
 * at every deploy (the files that were deployed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites_sites', function (Blueprint $table) {
            $table->longText('compose_snapshot')->nullable()->after('compose_adjustments');
        });
    }

    public function down(): void
    {
        Schema::table('sites_sites', function (Blueprint $table) {
            $table->dropColumn('compose_snapshot');
        });
    }
};
