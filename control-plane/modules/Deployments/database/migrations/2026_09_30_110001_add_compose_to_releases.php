<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compose releases keep their rendered files (encrypted: the .env holds the site's variables) so a rollback
 * re-applies exactly what ran, with images pinned to the digests the servers resolved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments_releases', function (Blueprint $table) {
            $table->text('compose')->nullable()->after('image');
        });
    }

    public function down(): void
    {
        Schema::table('deployments_releases', function (Blueprint $table) {
            $table->dropColumn('compose');
        });
    }
};
