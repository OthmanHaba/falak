<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The latest machine check per server (provision.inspect): the agent's report and the decisions taken from it. A
 * running re-check keeps the previous report until its result replaces it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers_machine_inspections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->unique()->constrained('servers_servers')->cascadeOnDelete();
            $table->ulid('command_id')->nullable()->index();
            $table->string('purpose', 16); // provision: apply once nothing blocks; check: report only
            $table->string('status', 16); // running, finished, failed
            $table->json('report')->nullable();
            $table->json('decisions')->nullable();
            $table->boolean('blocking')->default(false);
            $table->string('agent_version')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers_machine_inspections');
    }
};
