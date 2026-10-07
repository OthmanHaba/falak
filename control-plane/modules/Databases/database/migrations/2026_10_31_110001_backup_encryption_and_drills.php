<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.10.0 step 4: every backup is encrypted (FKB1: zstd, then AES-256-GCM, a data key per backup; docs/BACKUPS.md), and
 * schedules run restore drills. Backups taken before (gzip, unencrypted) keep their rows but are no longer restorable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('databases_backup_schedules', function (Blueprint $table) {
            $table->dropColumn('compression');
        });

        Schema::table('databases_backup_schedules', function (Blueprint $table) {
            // cp: the control plane holds each backup's key (sealed under the organization's key); customer: the agent
            // encrypts it to age_recipient and the control plane never has it.
            $table->string('encryption_mode', 8)->default('cp');
            $table->string('age_recipient', 80)->nullable();
            // Restore drills: off | weekly | monthly, an optional read-only check query, and another server of the
            // organization to run them on when the instance's own lacks the room.
            $table->string('drill', 8)->default('off');
            $table->text('drill_query')->nullable();
            $table->ulid('drill_server_id')->nullable();
            $table->timestamp('last_drill_at')->nullable();
            $table->timestamp('next_drill_at')->nullable()->index();
        });

        Schema::table('databases_backups', function (Blueprint $table) {
            $table->string('encryption_mode', 8)->nullable();
            // cp: the data key sealed under the organization's key (AAD "backup", organization, backup id).
            $table->text('wrapped_key')->nullable();
            $table->string('age_recipient', 80)->nullable();
            $table->string('cipher', 16)->nullable();
            // The dump's SHA-256 (authenticated in the file's trailer); sha256 is the stored file's.
            $table->char('plaintext_sha256', 64)->nullable();
            // Row count per table (Redis / Valkey: keys) just before the dump, for drills to compare with.
            $table->json('table_counts')->nullable();
            // The last drill of this backup: verified_at is set when it passed.
            $table->string('drill_status', 16)->nullable();
            $table->timestamp('verified_at')->nullable();
        });

        Schema::create('databases_drills', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('schedule_id')->nullable()->index();
            $table->ulid('backup_id')->nullable()->index();
            $table->ulid('database_instance_id')->nullable()->index();
            $table->string('database_name', 64)->nullable();
            // Where it ran: the instance's server or the schedule's drill server.
            $table->ulid('server_id')->nullable();
            $table->string('server_name')->nullable();
            $table->string('status', 16)->index();
            $table->string('reason', 1000)->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            // Download + restore time: what getting this backup back would take.
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
        Schema::dropIfExists('databases_drills');

        Schema::table('databases_backups', function (Blueprint $table) {
            $table->dropColumn(['encryption_mode', 'wrapped_key', 'age_recipient', 'cipher', 'plaintext_sha256', 'table_counts', 'drill_status', 'verified_at']);
        });

        Schema::table('databases_backup_schedules', function (Blueprint $table) {
            $table->dropIndex(['next_drill_at']);
            $table->dropColumn(['encryption_mode', 'age_recipient', 'drill', 'drill_query', 'drill_server_id', 'last_drill_at', 'next_drill_at']);
            $table->string('compression', 8)->default('gzip');
        });
    }
};
