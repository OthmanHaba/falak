<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.10.0: every managed database is a container (an instance). The tables of host engines are dropped and created
 * again; there is no data migration (a v0.9.0 install's host databases are simply no longer managed). Backup storage
 * providers stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Never silently: a v0.9.0 install's databases rows describe host engines that keep running but would no longer be
        // managed (nor backed up). The operator confirms with FALAK_DROP_LEGACY_DATABASES=1 (docs/INSTALL.md §5).
        $legacy = array_filter(['databases_servers', 'databases_databases', 'databases_users', 'databases_backup_schedules'], fn (string $table) => Schema::hasTable($table) && DB::table($table)->exists());

        if ($legacy !== [] && ! filter_var(env('FALAK_DROP_LEGACY_DATABASES', false), FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('Falak v0.10 runs every database in a container and no longer manages the host databases of earlier versions ('.implode(', ', $legacy).' have rows). Back them up, then set FALAK_DROP_LEGACY_DATABASES=1 and migrate again (docs/INSTALL.md §5). Nothing was changed.');
        }

        foreach (['databases_restores', 'databases_backups', 'databases_backup_schedule_database', 'databases_backup_schedules', 'databases_grants', 'databases_users', 'databases_databases', 'databases_servers', 'databases_instances'] as $table) {
            Schema::dropIfExists($table);
        }

        if (! Schema::hasTable('databases_storage_providers')) {
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
        }

        // One database container (falak-db-<id>) on a server.
        Schema::create('databases_instances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('server_id')->index();
            $table->string('server_name');
            // The project environment whose Docker network (falak-env-<id>) it joins; null: none.
            $table->ulid('environment_id')->nullable()->index();
            $table->string('name', 63);
            $table->string('engine', 16);
            $table->string('version', 16);
            $table->string('image', 255);
            $table->string('image_digest', 71)->nullable();
            // The DNS name apps use on the environment network (falak-db-<id>; a major upgrade's new instance takes
            // over the old one's).
            $table->string('hostname', 63);
            $table->unsignedInteger('port');
            $table->unsignedInteger('host_port')->nullable();
            // Private addresses the port is published on (for other servers), as the agent confirmed them; new ones wait
            // in pending_published_addresses until someone applies them (Docker binds ports only on a new container).
            $table->json('published_addresses')->nullable();
            $table->json('pending_published_addresses')->nullable();
            $table->ulid('network_command_id')->nullable();
            // Who may reach the published port (DOCKER-USER rules): the public allowlist set by people, and every source
            // last sent (consumers' private addresses included).
            $table->json('allowed_sources')->nullable();
            $table->json('firewall_sources')->nullable();
            $table->boolean('public_access')->default(false);
            $table->boolean('require_tls')->default(false);
            $table->ulid('volume_id')->nullable()->index();
            $table->unsignedBigInteger('memory_bytes');
            $table->decimal('cpus', 6, 2)->nullable();
            $table->json('settings')->nullable();
            // Point-in-time recovery (step 5 ships the spool); the column exists from the start.
            $table->boolean('pitr_enabled')->default(false);
            $table->text('root_password');
            // A rotation in flight (db.instance.password): becomes root_password once the agent confirms.
            $table->text('next_root_password')->nullable();
            // Redis / Valkey rotations overlap: the previous password stays valid until password_overlap_until.
            $table->text('previous_password')->nullable();
            $table->timestamp('password_overlap_until')->nullable()->index();
            // Deleting: the data volume goes too.
            $table->boolean('delete_volume')->default(false);
            $table->timestamp('tls_expires_at')->nullable()->index();
            // The names and addresses the certificate is valid for (a new one is issued when they change).
            $table->json('tls_hostnames')->nullable();
            $table->string('status', 16);
            $table->string('status_message', 1000)->nullable();
            $table->string('health', 16)->nullable();
            $table->timestamp('health_at')->nullable();
            $table->ulid('command_id')->nullable()->index();
            // Major upgrades: the new instance names the one it replaces. The old one is retired (read-only, stopped, its
            // volume kept until someone deletes it); its container goes at retire_at once its replacement is healthy.
            $table->ulid('upgrade_of')->nullable()->index();
            $table->ulid('replaced_by')->nullable();
            $table->timestamp('retire_at')->nullable()->index();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['server_id', 'host_port']);
        });

        Schema::create('databases_databases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('database_instance_id')->constrained('databases_instances')->cascadeOnDelete();
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
            $table->unique(['database_instance_id', 'name']);
        });

        Schema::create('databases_users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('database_instance_id')->constrained('databases_instances')->cascadeOnDelete();
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
            $table->unique(['database_instance_id', 'username']);
        });

        Schema::create('databases_grants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('databases_users')->cascadeOnDelete();
            $table->foreignUlid('database_id')->constrained('databases_databases')->cascadeOnDelete();
            $table->json('privileges');
            $table->timestamps();
            $table->unique(['user_id', 'database_id']);
        });

        Schema::create('databases_backup_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('database_instance_id')->constrained('databases_instances')->cascadeOnDelete();
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

        // Backup history outlives the database, the schedule and even the instance (objects stay in storage).
        Schema::create('databases_backups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('schedule_id')->nullable()->index();
            $table->ulid('database_id')->nullable()->index();
            $table->ulid('database_instance_id')->nullable()->index();
            $table->ulid('server_id')->index();
            $table->string('server_name');
            $table->string('instance_name', 63)->nullable();
            $table->string('database_name', 64);
            $table->string('engine', 16);
            $table->string('engine_version', 16)->nullable();
            $table->ulid('storage_provider_id')->nullable()->index();
            $table->string('object_key', 1024);
            $table->string('compression', 8);
            $table->string('trigger', 16);
            $table->string('status', 16)->index();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedBigInteger('uncompressed_bytes')->nullable();
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
            $table->ulid('database_instance_id')->index();
            $table->ulid('server_id')->index();
            $table->string('database_name', 64);
            $table->string('status', 16);
            $table->unsignedBigInteger('bytes')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->ulid('command_id')->nullable()->index();
            $table->string('error', 1000)->nullable();
            $table->json('warnings')->nullable();
            $table->ulid('requested_by')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['databases_restores', 'databases_backups', 'databases_backup_schedule_database', 'databases_backup_schedules', 'databases_grants', 'databases_users', 'databases_databases', 'databases_instances'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
