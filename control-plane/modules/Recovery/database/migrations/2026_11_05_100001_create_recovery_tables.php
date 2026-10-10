<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Set up disaster recovery" banners a user dismissed, until when.
        Schema::create('recovery_dismissals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('user_id');
            $table->string('key', 64);
            $table->timestamp('dismissed_until');
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });

        // "This server is gone": one run of the wizard. steps holds each step's items and their state (Recovery's
        // ServerRecoveryRunner advances them); plan is the dry run it started from.
        Schema::create('recovery_server_recoveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('lost_server_id')->index();
            $table->string('lost_server_name');
            $table->ulid('target_server_id');
            $table->string('target_server_name');
            $table->string('status', 16);
            $table->string('current_step', 32)->nullable();
            $table->json('steps');
            $table->json('plan');
            $table->ulid('requested_by')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_server_recoveries');
        Schema::dropIfExists('recovery_dismissals');
    }
};
