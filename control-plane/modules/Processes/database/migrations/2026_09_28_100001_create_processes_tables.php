<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Queue workers of a site (php artisan queue:work, or a custom command for node/bun/deno sites).
        Schema::create('processes_workers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('site_id')->index();
            $table->string('connection', 64)->nullable();
            $table->string('queue', 255)->nullable();
            $table->string('command', 1000)->nullable();
            $table->unsignedSmallInteger('processes')->default(1);
            $table->unsignedInteger('timeout')->default(60);
            $table->unsignedInteger('sleep')->default(3);
            $table->unsignedSmallInteger('tries')->default(1);
            $table->unsignedInteger('backoff')->nullable();
            $table->unsignedInteger('max_jobs')->nullable();
            $table->unsignedInteger('max_time')->nullable();
            $table->unsignedInteger('memory')->default(128);
            $table->text('env')->nullable();
            $table->json('server_ids')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
        });

        // Long-running commands of a site.
        Schema::create('processes_daemons', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('site_id')->index();
            $table->string('name', 64);
            $table->string('command', 2000);
            $table->string('directory', 255)->nullable();
            $table->string('user', 32)->nullable();
            $table->unsignedSmallInteger('instances')->default(1);
            $table->string('restart', 16)->default('always');
            $table->string('stop_signal', 8)->default('TERM');
            $table->unsignedInteger('stop_timeout')->default(30);
            $table->text('env')->nullable();
            $table->json('server_ids')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
        });

        // Custom scheduled jobs of a site (the Laravel scheduler itself is a Sites toggle).
        Schema::create('processes_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('site_id')->index();
            $table->string('name', 64);
            $table->string('command', 2000);
            $table->string('expression', 64);
            $table->string('timezone', 64)->default('UTC');
            $table->string('user', 32)->nullable();
            $table->string('overlap', 8)->default('skip');
            $table->unsignedInteger('timeout')->default(3600);
            $table->boolean('heartbeat')->default(true);
            $table->boolean('enabled')->default(true);
            $table->boolean('all_servers')->default(false);
            $table->ulid('created_by')->nullable();
            $table->timestamps();
        });

        // Last proc.apply / cron.apply / proc.status per server.
        Schema::create('processes_server_states', function (Blueprint $table) {
            $table->ulid('server_id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('proc_sha256', 64)->nullable();
            $table->ulid('proc_command_id')->nullable()->index();
            $table->string('proc_status', 16)->nullable();
            $table->string('proc_error', 1000)->nullable();
            $table->json('programs')->nullable();
            $table->json('applied_programs')->nullable();
            $table->timestamp('proc_dispatched_at')->nullable();
            $table->timestamp('proc_applied_at')->nullable();
            $table->string('cron_sha256', 64)->nullable();
            $table->ulid('cron_command_id')->nullable()->index();
            $table->string('cron_status', 16)->nullable();
            $table->string('cron_error', 1000)->nullable();
            $table->json('jobs')->nullable();
            $table->json('applied_jobs')->nullable();
            $table->timestamp('cron_dispatched_at')->nullable();
            $table->timestamp('cron_applied_at')->nullable();
            $table->json('process_status')->nullable();
            $table->timestamp('status_at')->nullable();
            $table->json('crash_looping')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processes_server_states');
        Schema::dropIfExists('processes_schedules');
        Schema::dropIfExists('processes_daemons');
        Schema::dropIfExists('processes_workers');
    }
};
