<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('volumes_volumes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            // shared_path volumes have no server: they live on every server of their site.
            $table->ulid('server_id')->nullable()->index();
            $table->string('name', 128);
            // docker | sized | bind | shared_path
            $table->string('kind', 16);
            // docker: the Docker volume's name (compose: <project>_<key> or its `name:`).
            $table->string('docker_name', 128)->nullable();
            // bind / shared_path: the host path; sized and docker: where the agent reported the data lives.
            $table->string('host_path', 1024)->nullable();
            $table->unsignedBigInteger('size_limit_bytes')->nullable();
            $table->unsignedBigInteger('used_bytes')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->boolean('protected')->default(false);
            $table->json('labels')->nullable();
            // Kind-specific: shared_path {type: file|directory}; compose {compose: {site_id, key}, external}.
            $table->json('options')->nullable();
            // pending | active | failed | deleting
            $table->string('status', 16)->default('pending');
            $table->string('status_message', 1000)->nullable();
            $table->ulid('command_id')->nullable()->index();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->index(['server_id', 'docker_name']);
            // Shared paths (no server) are named <site slug>/<path>.
            $table->unique(['server_id', 'name']);
        });

        Schema::create('volumes_attachments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('volume_id')->constrained('volumes_volumes')->cascadeOnDelete();
            // site | compose_service | database (step 3)
            $table->string('attachable_type', 16);
            // The site (compose_service: the compose site) or database id.
            $table->ulid('attachable_id');
            // compose_service: the service of the stack.
            $table->string('service', 128)->nullable();
            $table->string('mount_path', 1024);
            $table->boolean('read_only')->default(false);
            $table->timestamps();
            $table->index(['attachable_type', 'attachable_id']);
        });

        // Agent work on volumes that outlives one request: create, resize, delete, clone, restore, move, download.
        Schema::create('volumes_operations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('volume_id')->nullable()->constrained('volumes_volumes')->nullOnDelete();
            $table->string('kind', 16);
            // pending | running | succeeded | failed
            $table->string('status', 16)->default('pending');
            // Multi-step operations (move, clone to another server): archive → restore → delete.
            $table->string('step', 16)->nullable();
            $table->ulid('command_id')->nullable()->index();
            $table->json('meta')->nullable();
            $table->json('result')->nullable();
            $table->string('error', 1000)->nullable();
            $table->ulid('requested_by')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('volumes_backup_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('volume_id')->constrained('volumes_volumes')->cascadeOnDelete();
            $table->ulid('storage_provider_id')->nullable();
            $table->string('cron', 64);
            $table->unsignedInteger('retention_count')->nullable();
            $table->unsignedInteger('retention_days')->nullable();
            $table->string('consistency', 8)->default('none');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
        });

        // Outlives its volume (a restore brings it back), hence the copies of its name and server.
        Schema::create('volumes_backups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('volume_id')->nullable()->index();
            $table->string('volume_name', 128);
            $table->string('volume_kind', 16);
            $table->ulid('server_id')->nullable();
            $table->ulid('schedule_id')->nullable()->index();
            $table->ulid('storage_provider_id')->nullable();
            $table->string('object_key', 1024);
            $table->string('consistency', 8)->default('none');
            $table->string('trigger', 16)->default('manual');
            // pending | succeeded | failed | pruned
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedBigInteger('uncompressed_bytes')->nullable();
            $table->unsignedBigInteger('volume_size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error', 1000)->nullable();
            $table->string('prune_error', 1000)->nullable();
            $table->ulid('command_id')->nullable()->index();
            $table->ulid('requested_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('pruned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volumes_backups');
        Schema::dropIfExists('volumes_backup_schedules');
        Schema::dropIfExists('volumes_operations');
        Schema::dropIfExists('volumes_attachments');
        Schema::dropIfExists('volumes_volumes');
    }
};
