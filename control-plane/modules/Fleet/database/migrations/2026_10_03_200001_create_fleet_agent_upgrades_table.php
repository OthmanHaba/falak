<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_agent_upgrades', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('agent_id')->index();
            $table->ulid('server_id')->index();
            // Upgrades started together by "Upgrade all agents" share a rollout id and run a few at a time.
            $table->ulid('rollout_id')->nullable()->index();
            $table->string('status', 16)->index();
            $table->string('arch', 16);
            $table->string('from_version', 64)->nullable();
            $table->string('to_version', 64);
            $table->string('sha256', 64);
            $table->string('command_id', 26)->nullable()->index();
            $table->boolean('installed')->default(false);
            $table->text('error')->nullable();
            $table->ulid('requested_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_agent_upgrades');
    }
};
