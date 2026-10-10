<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // OOM kills and restarts per service and server (agents' heartbeat service_events): restart-loop windows and
        // the badges on services' cards.
        Schema::create('limits_service_states', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('server_id');
            $table->ulid('site_id')->nullable()->index();
            $table->string('service_kind', 32);
            $table->string('service_id', 128);
            $table->string('label', 255);
            $table->unsignedInteger('oom_kills')->default(0);
            $table->timestamp('last_oom_at')->nullable();
            $table->unsignedInteger('restarts')->default(0);
            $table->unsignedInteger('window_restarts')->default(0);
            $table->timestamp('window_started_at')->nullable();
            $table->timestamp('last_restart_at')->nullable();
            $table->timestamp('restart_loop_at')->nullable();
            $table->timestamps();
            $table->unique(['server_id', 'service_kind', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limits_service_states');
    }
};
