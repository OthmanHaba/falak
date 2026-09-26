<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Volume tables (insights_exceptions, insights_aggregates, insights_heartbeat_runs) carry a
 * `bucket_date` column and are only ever pruned by it, so they can be converted to native
 * Postgres `PARTITION BY RANGE (bucket_date)` (one partition per day) without code changes;
 * their surrogate keys are plain bigints and no other table references them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insights_sites', function (Blueprint $table) {
            $table->ulid('organization_id');
            $table->ulid('site_id');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->primary(['organization_id', 'site_id']);
        });

        Schema::create('insights_issues', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('site_id')->nullable();
            $table->ulid('server_id')->nullable();
            $table->string('kind', 16);
            $table->char('fingerprint', 64);
            $table->string('status', 16);
            $table->string('priority', 16)->default('none');
            $table->string('title', 500);
            $table->string('culprit', 500)->nullable();
            $table->string('exception_type')->nullable();
            $table->string('event_type', 32)->nullable();
            $table->unsignedBigInteger('occurrences')->default(0);
            $table->unsignedBigInteger('unhandled_occurrences')->default(0);
            $table->unsignedInteger('affected_users')->default(0);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->ulid('resolved_by')->nullable();
            $table->timestamp('ignored_at')->nullable();
            $table->timestamp('regressed_at')->nullable();
            $table->unsignedInteger('regressions')->default(0);
            $table->ulid('assignee_id')->nullable()->index();
            $table->string('last_trace_id', 32)->nullable();
            $table->json('sample')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'kind', 'fingerprint']);
            $table->index(['organization_id', 'status', 'last_seen_at']);
            $table->index(['organization_id', 'site_id', 'last_seen_at']);
        });

        Schema::create('insights_exceptions', function (Blueprint $table) {
            $table->id();
            $table->date('bucket_date');
            $table->timestamp('occurred_at', 3);
            $table->timestamp('minute');
            $table->ulid('organization_id');
            $table->ulid('site_id');
            $table->ulid('server_id')->nullable();
            $table->ulid('issue_id');
            $table->char('dedupe_hash', 40)->unique();
            $table->string('type');
            $table->text('message');
            $table->text('stacktrace')->nullable();
            $table->boolean('handled');
            $table->string('user_hash', 64)->nullable();
            $table->string('event_type', 32)->nullable();
            $table->string('route_or_name', 1000)->nullable();
            $table->string('trace_id', 32)->nullable();
            $table->string('span_id', 16)->nullable();

            $table->index(['issue_id', 'occurred_at']);
            $table->index(['organization_id', 'site_id', 'minute']);
            $table->index('bucket_date');
        });

        Schema::create('insights_aggregates', function (Blueprint $table) {
            $table->id();
            $table->date('bucket_date');
            $table->timestamp('minute');
            $table->ulid('organization_id');
            $table->ulid('site_id');
            // Reporting server (or agent when the agent is not bound to a server).
            $table->ulid('source_id');
            $table->string('event_type', 32);
            $table->string('name', 1000);
            $table->char('name_hash', 40);
            $table->unsignedInteger('count');
            $table->unsignedInteger('errors')->default(0);
            $table->double('p50_ms')->default(0);
            $table->double('p95_ms')->default(0);
            $table->double('max_ms')->default(0);

            // Agents may re-send a batch (disk buffer retries): the natural key makes ingest idempotent.
            $table->unique(['site_id', 'event_type', 'name_hash', 'minute', 'source_id'], 'insights_aggregates_natural');
            $table->index(['organization_id', 'site_id', 'event_type', 'minute'], 'insights_aggregates_site_type_minute');
            $table->index('bucket_date');
        });

        Schema::create('insights_issue_users', function (Blueprint $table) {
            $table->ulid('issue_id');
            $table->char('user_hash', 64);
            $table->timestamp('first_seen_at');
            $table->primary(['issue_id', 'user_hash']);
        });

        Schema::create('insights_issue_comments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('issue_id')->index();
            $table->ulid('user_id');
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('insights_issue_activities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('issue_id');
            $table->ulid('user_id')->nullable();
            $table->string('type', 32);
            $table->json('data')->nullable();
            $table->timestamp('created_at');
            $table->index(['issue_id', 'created_at']);
        });

        Schema::create('insights_thresholds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('site_id');
            $table->string('event_type', 32);
            $table->string('name_pattern', 500)->nullable();
            $table->string('metric', 8);
            $table->double('threshold_ms');
            $table->unsignedSmallInteger('window_minutes');
            $table->unsignedInteger('min_count')->default(1);
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamp('last_breached_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'site_id']);
            $table->index('enabled');
        });

        Schema::create('insights_heartbeat_monitors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('site_id')->nullable();
            $table->ulid('server_id')->nullable();
            // server id, or the agent id when the agent is not bound to a server
            $table->ulid('source_id');
            $table->string('job', 191);
            $table->string('schedule', 191)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->unsignedInteger('grace_seconds')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('last_status', 16)->nullable();
            $table->integer('last_exit_code')->nullable();
            $table->unsignedInteger('last_duration_ms')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_scheduled_at')->nullable();
            $table->timestamp('next_expected_at')->nullable();
            $table->timestamp('missed_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'source_id', 'job']);
            $table->index(['enabled', 'next_expected_at']);
        });

        Schema::create('insights_heartbeat_runs', function (Blueprint $table) {
            $table->id();
            $table->date('bucket_date');
            $table->ulid('monitor_id');
            $table->string('status', 16);
            $table->integer('exit_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('scheduled_at');
            $table->timestamp('at');
            $table->unique(['monitor_id', 'scheduled_at']);
            $table->index('bucket_date');
        });
    }

    public function down(): void
    {
        foreach (['heartbeat_runs', 'heartbeat_monitors', 'thresholds', 'issue_activities', 'issue_comments', 'issue_users', 'aggregates', 'exceptions', 'issues', 'sites'] as $table) {
            Schema::dropIfExists("insights_{$table}");
        }
    }
};
