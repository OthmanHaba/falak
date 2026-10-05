<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * falak-builder processes identify themselves with a run id (new per process start): a build claimed by an earlier
 * run of the same builder name is orphaned the moment the restarted builder polls, and a running build whose
 * builder stops heartbeating is failed after builds.heartbeat_timeout_seconds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builds_builds', function (Blueprint $table) {
            $table->string('builder_name', 100)->nullable();
            $table->string('builder_run_id', 64)->nullable();
            $table->timestamp('heartbeat_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('builds_builds', function (Blueprint $table) {
            $table->dropColumn(['builder_name', 'builder_run_id', 'heartbeat_at']);
        });
    }
};
