<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.10.0 step 5: point-in-time recovery. An instance with PITR on ships its WAL / binlogs (segments, each an FKB1
 * file with its own key) and takes physical base backups (databases_backups of type base); a restore to a time makes a
 * new instance that waits for a decision (swap, keep, discard).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('databases_instances', function (Blueprint $table) {
            $table->ulid('pitr_storage_provider_id')->nullable()->index();
            // cp: a data key per segment and base, kept sealed on its row; customer: encrypted to pitr_age_recipient.
            $table->string('pitr_encryption_mode', 8)->default('cp');
            $table->string('pitr_age_recipient', 80)->nullable();
            // Recovery points kept (days), and how often a new base backup is taken (days).
            $table->unsignedSmallInteger('pitr_window_days')->default(7);
            $table->unsignedSmallInteger('pitr_base_interval_days')->default(7);
            $table->timestamp('pitr_next_base_at')->nullable()->index();
            $table->timestamp('pitr_last_shipped_at')->nullable();
            // The heartbeat's last word on the spool: spool_bytes, volume_bytes, pending, oldest_pending_at, error, at.
            $table->json('pitr_report')->nullable();
            // A point-in-time restore's new instance names the instance it was restored from (until a decision).
            $table->ulid('restored_from')->nullable()->index();
        });

        Schema::table('databases_backups', function (Blueprint $table) {
            // logical (a dump of one database) | base (a physical backup of the whole instance, for PITR).
            $table->string('type', 8)->default('logical')->index();
            // Bases: where in the log they start (postgres start_wal, mysql/mariadb the binlog) and end (stop_wal), and
            // falak-db's start and end on the server's clock (the segments' times are on it too).
            $table->string('log_start', 64)->nullable();
            $table->string('log_stop', 64)->nullable();
            $table->timestamp('base_started_at', 6)->nullable();
            $table->timestamp('base_finished_at', 6)->nullable();
        });

        // One shipped WAL segment or binlog (an FKB1 object of its own).
        Schema::create('databases_pitr_segments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('database_instance_id')->index();
            $table->ulid('server_id');
            $table->string('kind', 8);
            $table->string('name', 128);
            $table->ulid('storage_provider_id')->nullable()->index();
            $table->string('object_key', 1024);
            $table->string('encryption_mode', 8);
            // cp: the segment's data key sealed under the organization's key (AAD "backup", organization, segment id).
            $table->text('wrapped_key')->nullable();
            $table->string('age_recipient', 80)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedBigInteger('plaintext_bytes')->nullable();
            // The spool file's SHA-256 (asked for before the upload; authenticated in the file's trailer).
            $table->char('plaintext_sha256', 64);
            // When falak-db spooled it (the server's clock): the segment holds nothing later.
            $table->timestamp('end_time', 6)->nullable()->index();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamps();
            $table->unique(['database_instance_id', 'kind', 'name', 'plaintext_sha256'], 'databases_pitr_segments_unique');
        });

        // A break in the log chain (binlog-rotate exit 4): recovery can't cross it; the next base starts a new range.
        Schema::create('databases_pitr_gaps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('database_instance_id')->index();
            $table->string('kind', 8);
            $table->string('gap', 16);
            $table->string('from', 128)->nullable();
            $table->string('to', 128)->nullable();
            $table->string('detail', 1000);
            $table->timestamp('detected_at', 6);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::table('databases_restores', function (Blueprint $table) {
            // backup (a dump into an existing database) | pitr (a new instance at target_time, awaiting a decision).
            $table->string('type', 8)->default('backup');
            $table->string('database_name', 64)->nullable()->change();
            $table->timestamp('target_time', 6)->nullable();
            $table->ulid('source_instance_id')->nullable()->index();
            $table->ulid('restored_instance_id')->nullable()->index();
            $table->unsignedInteger('segments')->nullable();
            $table->json('table_counts')->nullable();
            // swap | keep | discard
            $table->string('decision', 8)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->ulid('decided_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('databases_restores', function (Blueprint $table) {
            $table->dropIndex(['source_instance_id']);
            $table->dropIndex(['restored_instance_id']);
            $table->dropColumn(['type', 'target_time', 'source_instance_id', 'restored_instance_id', 'segments', 'table_counts', 'decision', 'decided_at', 'decided_by']);
        });

        Schema::dropIfExists('databases_pitr_gaps');
        Schema::dropIfExists('databases_pitr_segments');

        Schema::table('databases_backups', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'log_start', 'log_stop', 'base_started_at', 'base_finished_at']);
        });

        Schema::table('databases_instances', function (Blueprint $table) {
            $table->dropIndex(['pitr_storage_provider_id']);
            $table->dropIndex(['pitr_next_base_at']);
            $table->dropIndex(['restored_from']);
            $table->dropColumn(['pitr_storage_provider_id', 'pitr_encryption_mode', 'pitr_age_recipient', 'pitr_window_days', 'pitr_base_interval_days',
                'pitr_next_base_at', 'pitr_last_shipped_at', 'pitr_report', 'restored_from']);
        });
    }
};
