<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.10.0 step 4: volume archives are encrypted like database backups (FKB1; docs/BACKUPS.md) and schedules run
 * restore drills. Archives taken before are no longer restorable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volumes_backup_schedules', function (Blueprint $table) {
            $table->string('encryption_mode', 8)->default('cp');
            $table->string('age_recipient', 80)->nullable();
            $table->string('drill', 8)->default('off');
            $table->ulid('drill_server_id')->nullable();
            $table->timestamp('last_drill_at')->nullable();
            $table->timestamp('next_drill_at')->nullable()->index();
        });

        Schema::table('volumes_backups', function (Blueprint $table) {
            $table->string('encryption_mode', 8)->nullable();
            $table->text('wrapped_key')->nullable();
            $table->string('age_recipient', 80)->nullable();
            $table->string('cipher', 16)->nullable();
            $table->string('compression', 8)->nullable();
            $table->char('plaintext_sha256', 64)->nullable();
            $table->unsignedBigInteger('files')->nullable();
            $table->string('drill_status', 16)->nullable();
            $table->timestamp('verified_at')->nullable();
        });

        Schema::create('volumes_drills', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('schedule_id')->nullable()->index();
            $table->ulid('backup_id')->nullable()->index();
            $table->ulid('volume_id')->nullable()->index();
            $table->string('volume_name', 128)->nullable();
            $table->ulid('server_id')->nullable();
            $table->string('status', 16)->index();
            $table->string('reason', 1000)->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->unsignedInteger('rto_estimate_seconds')->nullable();
            $table->json('checks')->nullable();
            $table->string('error', 1000)->nullable();
            $table->ulid('command_id')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volumes_drills');

        Schema::table('volumes_backups', function (Blueprint $table) {
            $table->dropColumn(['encryption_mode', 'wrapped_key', 'age_recipient', 'cipher', 'compression', 'plaintext_sha256', 'files', 'drill_status', 'verified_at']);
        });

        Schema::table('volumes_backup_schedules', function (Blueprint $table) {
            $table->dropIndex(['next_drill_at']);
            $table->dropColumn(['encryption_mode', 'age_recipient', 'drill', 'drill_server_id', 'last_drill_at', 'next_drill_at']);
        });
    }
};
