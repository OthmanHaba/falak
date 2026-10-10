<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.10.0 step 8: heartbeats report every data filesystem (`disks`); the samples keep them, compact
 * ({mount: [used, available, total]}), for per-mount disk alerts and fill forecasts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_agent_metrics', function (Blueprint $table) {
            $table->json('disks')->nullable()->after('disk_used_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('fleet_agent_metrics', fn (Blueprint $table) => $table->dropColumn('disks'));
    }
};
