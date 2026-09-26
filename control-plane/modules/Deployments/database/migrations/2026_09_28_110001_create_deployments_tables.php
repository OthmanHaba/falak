<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployments_site_settings', function (Blueprint $table) {
            $table->ulid('site_id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('strategy', 16)->nullable();
            $table->unsignedSmallInteger('batch_size')->default(1);
            $table->unsignedSmallInteger('keep_releases')->default(5);
            $table->boolean('health_enabled')->default(true);
            $table->string('health_path')->nullable();
            $table->unsignedSmallInteger('health_status')->default(200);
            $table->unsignedSmallInteger('health_timeout_s')->default(10);
            $table->unsignedSmallInteger('health_retries')->default(3);
            $table->unsignedSmallInteger('health_retry_delay_s')->default(5);
            $table->string('hook_token_hash', 64)->nullable()->unique();
            $table->text('hook_token')->nullable();
            $table->timestamps();
        });

        Schema::create('deployments_deployments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('site_id');
            $table->string('site_slug', 63);
            $table->unsignedInteger('number');
            $table->string('trigger', 16);
            $table->string('status', 16);
            $table->string('phase', 16)->nullable();
            $table->string('strategy', 16)->nullable();
            $table->string('branch')->nullable();
            $table->string('commit', 64)->nullable();
            $table->string('commit_message', 1000)->nullable();
            $table->string('commit_author')->nullable();
            $table->ulid('build_id')->nullable()->index();
            $table->ulid('release_id')->nullable();
            $table->ulid('target_release_id')->nullable();
            $table->ulid('previous_release_id')->nullable();
            $table->boolean('rolling_back')->default(false);
            $table->boolean('rolled_back')->default(false);
            $table->boolean('cancel_requested')->default(false);
            $table->text('variables')->nullable();
            $table->json('settings')->nullable();
            $table->string('error', 2000)->nullable();
            $table->ulid('requested_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'number']);
            $table->index(['site_id', 'status']);
        });

        Schema::create('deployments_targets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('deployment_id')->index();
            $table->ulid('server_id');
            $table->string('server_name');
            $table->string('role', 16);
            $table->unsignedSmallInteger('batch')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('status', 16);
            $table->boolean('activated')->default(false);
            $table->ulid('previous_release_id')->nullable();
            $table->string('error', 2000)->nullable();
            $table->timestamps();
        });

        Schema::create('deployments_steps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('deployment_id');
            $table->ulid('target_id')->nullable();
            $table->ulid('server_id')->nullable();
            $table->string('key', 120);
            $table->string('kind', 24);
            $table->string('phase', 16);
            $table->boolean('rollback')->default(false);
            $table->unsignedSmallInteger('batch')->default(0);
            $table->unsignedInteger('position');
            $table->json('depends_on');
            $table->json('meta')->nullable();
            $table->string('status', 16);
            $table->string('command_type', 64)->nullable();
            $table->ulid('command_id')->nullable()->unique();
            $table->ulid('build_id')->nullable()->index();
            $table->string('idempotency_key', 191)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->integer('exit_code')->nullable();
            $table->json('result')->nullable();
            $table->string('error', 2000)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['deployment_id', 'key']);
        });

        Schema::create('deployments_output', function (Blueprint $table) {
            // The auto-increment id is the output cursor (`seq`, ?after=).
            $table->id();
            $table->ulid('deployment_id');
            $table->ulid('step_id')->nullable();
            $table->ulid('server_id')->nullable();
            $table->string('server_name')->nullable();
            $table->string('phase', 16)->nullable();
            $table->string('stream', 8);
            $table->text('data');
            $table->unsignedBigInteger('source_seq')->nullable();
            $table->timestamp('at');

            $table->index(['deployment_id', 'id']);
            $table->unique(['step_id', 'source_seq']);
        });

        Schema::create('deployments_releases', function (Blueprint $table) {
            // The release id doubles as the release directory name (uppercase on servers).
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('site_id');
            $table->ulid('deployment_id');
            $table->ulid('build_id')->nullable();
            $table->string('commit', 64)->nullable();
            $table->string('branch')->nullable();
            $table->string('commit_message', 1000)->nullable();
            $table->string('commit_author')->nullable();
            $table->string('image')->nullable();
            $table->string('status', 16);
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['deployments_releases', 'deployments_output', 'deployments_steps', 'deployments_targets', 'deployments_deployments', 'deployments_site_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
