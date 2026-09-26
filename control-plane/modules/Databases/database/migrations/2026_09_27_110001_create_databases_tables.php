<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A database engine running on a server (one per server).
        Schema::create('databases_servers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('server_id')->unique();
            $table->string('server_name');
            $table->string('engine', 16);
            $table->string('version', 16)->nullable();
            $table->string('version_source', 16)->default('default');
            $table->boolean('dedicated')->default(false);
            $table->unsignedInteger('port');
            $table->timestamps();
        });

        Schema::create('databases_databases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('database_server_id')->constrained('databases_servers')->cascadeOnDelete();
            $table->ulid('server_id')->index();
            $table->string('name', 64);
            $table->string('charset', 32)->nullable();
            $table->string('collation', 64)->nullable();
            $table->ulid('site_id')->nullable()->index();
            $table->string('status', 16);
            $table->string('status_message', 1000)->nullable();
            $table->ulid('command_id')->nullable()->index();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['database_server_id', 'name']);
        });

        Schema::create('databases_users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('database_server_id')->constrained('databases_servers')->cascadeOnDelete();
            $table->ulid('server_id')->index();
            $table->string('username', 64);
            $table->text('password');
            $table->string('host', 255)->default('%');
            $table->ulid('site_id')->nullable()->index();
            $table->string('status', 16);
            $table->string('status_message', 1000)->nullable();
            $table->ulid('command_id')->nullable()->index();
            $table->unsignedInteger('revision')->default(0);
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['database_server_id', 'username']);
        });

        Schema::create('databases_grants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('databases_users')->cascadeOnDelete();
            $table->foreignUlid('database_id')->constrained('databases_databases')->cascadeOnDelete();
            $table->json('privileges');
            $table->timestamps();
            $table->unique(['user_id', 'database_id']);
        });

        Schema::create('databases_storage_providers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('name');
            $table->string('driver', 16);
            $table->string('endpoint')->nullable();
            $table->string('region', 64);
            $table->string('bucket', 255);
            $table->string('prefix', 255)->nullable();
            $table->boolean('path_style')->default(false);
            $table->text('access_key_id');
            $table->text('secret_access_key');
            $table->timestamp('verified_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('databases_backup_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('database_server_id')->constrained('databases_servers')->cascadeOnDelete();
            $table->foreignUlid('storage_provider_id')->constrained('databases_storage_providers')->restrictOnDelete();
            $table->string('name');
            $table->string('cron', 120);
            $table->unsignedInteger('retention_count')->nullable();
            $table->unsignedInteger('retention_days')->nullable();
            $table->string('compression', 8)->default('gzip');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('databases_backup_schedule_database', function (Blueprint $table) {
            $table->foreignUlid('schedule_id')->constrained('databases_backup_schedules')->cascadeOnDelete();
            $table->foreignUlid('database_id')->constrained('databases_databases')->cascadeOnDelete();
            $table->primary(['schedule_id', 'database_id']);
        });

        // Backup history outlives the database, the schedule and even the server (objects stay in storage).
        Schema::create('databases_backups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('schedule_id')->nullable()->index();
            $table->ulid('database_id')->nullable()->index();
            $table->ulid('database_server_id')->nullable()->index();
            $table->ulid('server_id')->index();
            $table->string('server_name');
            $table->string('database_name', 64);
            $table->string('engine', 16);
            $table->ulid('storage_provider_id')->nullable()->index();
            $table->string('object_key', 1024);
            $table->string('compression', 8);
            $table->string('trigger', 16);
            $table->string('status', 16)->index();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->ulid('command_id')->nullable()->index();
            $table->string('error', 1000)->nullable();
            $table->string('prune_error', 1000)->nullable();
            $table->ulid('requested_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('pruned_at')->nullable();
            $table->timestamps();
        });

        Schema::create('databases_restores', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('backup_id')->constrained('databases_backups')->cascadeOnDelete();
            $table->ulid('database_server_id')->index();
            $table->ulid('server_id')->index();
            $table->string('database_name', 64);
            $table->string('status', 16);
            $table->unsignedBigInteger('bytes')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->ulid('command_id')->nullable()->index();
            $table->string('error', 1000)->nullable();
            $table->ulid('requested_by')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('databases_restores');
        Schema::dropIfExists('databases_backups');
        Schema::dropIfExists('databases_backup_schedule_database');
        Schema::dropIfExists('databases_backup_schedules');
        Schema::dropIfExists('databases_storage_providers');
        Schema::dropIfExists('databases_grants');
        Schema::dropIfExists('databases_users');
        Schema::dropIfExists('databases_databases');
        Schema::dropIfExists('databases_servers');
    }
};
