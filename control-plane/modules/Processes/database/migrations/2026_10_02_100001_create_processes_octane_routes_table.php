<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Octane of a site on one server: whether it was verified listening (Edge proxies to it only then) or is draining
        // (Octane switched off: the program keeps running until the edge stopped proxying to it).
        Schema::create('processes_octane_routes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('site_id');
            $table->ulid('server_id')->index();
            $table->string('octane_server', 16);
            $table->unsignedInteger('port');
            $table->string('status', 16);
            $table->string('probe_command_id', 64)->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('listening_at')->nullable();
            $table->timestamp('draining_since')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'server_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processes_octane_routes');
    }
};
