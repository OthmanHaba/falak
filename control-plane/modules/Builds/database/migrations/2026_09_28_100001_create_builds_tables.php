<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('builds_builders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Null = shared builder (the control-plane host) serving every organization.
            $table->ulid('organization_id')->nullable()->index();
            $table->string('name', 100);
            $table->string('kind', 16);
            $table->ulid('server_id')->nullable()->unique();
            $table->string('token_hash', 64)->unique();
            $table->json('modes');
            $table->boolean('enabled')->default(true);
            $table->string('reported_name', 100)->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->ulid('install_command_id')->nullable();
            $table->timestamps();
        });

        Schema::create('builds_builds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('site_id');
            $table->string('site_slug', 63);
            $table->ulid('deployment_id')->nullable()->index();
            $table->string('mode', 16);
            $table->string('status', 16)->index();
            $table->ulid('builder_id')->nullable()->index();
            $table->string('repository')->nullable();
            $table->string('branch')->nullable();
            $table->string('commit', 64)->nullable();
            $table->string('resolved_commit', 64)->nullable();
            $table->string('cache_key', 64)->index();
            $table->ulid('reused_build_id')->nullable();
            $table->unsignedInteger('timeout_s');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->float('progress')->nullable();
            $table->string('artifact_key')->nullable();
            $table->string('artifact_sha256', 64)->nullable();
            $table->unsignedBigInteger('artifact_size')->nullable();
            $table->string('artifact_format', 8)->nullable();
            $table->timestamp('artifact_pruned_at')->nullable();
            $table->string('image_ref')->nullable();
            $table->string('image_digest')->nullable();
            $table->json('manifest')->nullable();
            $table->integer('exit_code')->nullable();
            $table->string('error', 2000)->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->ulid('requested_by')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('builds_logs', function (Blueprint $table) {
            // The auto-increment id doubles as a global, monotonic cursor.
            $table->id();
            $table->ulid('build_id');
            $table->unsignedBigInteger('seq')->nullable(); // builder event seq; null for control-plane lines
            $table->string('stream', 8);
            $table->text('data');
            $table->timestamp('at');

            $table->unique(['build_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('builds_logs');
        Schema::dropIfExists('builds_builds');
        Schema::dropIfExists('builds_builders');
    }
};
