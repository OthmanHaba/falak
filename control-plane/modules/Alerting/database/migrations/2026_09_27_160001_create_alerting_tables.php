<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerting_channels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('type', 16);
            $table->string('name');
            $table->text('config');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('alerting_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('name');
            $table->json('event_types');
            $table->string('min_severity', 16)->default('info');
            $table->boolean('enabled')->default(true);
            $table->json('quiet_hours')->nullable();
            $table->unsignedInteger('rate_limit_per_hour')->nullable();
            $table->timestamps();
        });

        Schema::create('alerting_rule_channels', function (Blueprint $table) {
            $table->foreignUlid('rule_id')->constrained('alerting_rules')->cascadeOnDelete();
            $table->foreignUlid('channel_id')->constrained('alerting_channels')->cascadeOnDelete();
            $table->primary(['rule_id', 'channel_id']);
        });

        Schema::create('alerting_alerts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('type', 100);
            $table->string('severity', 16);
            $table->string('title', 500);
            $table->text('body')->nullable();
            $table->string('url', 2000)->nullable();
            $table->string('dedup_key', 255)->nullable();
            $table->boolean('recovery')->default(false);
            $table->json('context')->nullable();
            $table->string('outcome', 32);
            $table->json('matched_rule_ids')->nullable();
            $table->timestamp('created_at')->index();
            $table->index(['organization_id', 'created_at']);
        });

        // One row per (rule, delivered alert): drives per-rule rate limiting.
        Schema::create('alerting_rule_hits', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('rule_id')->constrained('alerting_rules')->cascadeOnDelete();
            $table->ulid('alert_id');
            $table->timestamp('created_at');
            $table->index(['rule_id', 'created_at']);
        });

        Schema::create('alerting_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('alert_id')->constrained('alerting_alerts')->cascadeOnDelete();
            $table->foreignUlid('channel_id')->constrained('alerting_channels')->cascadeOnDelete();
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error', 1000)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['channel_id', 'created_at']);
        });

        Schema::create('alerting_dedup_states', function (Blueprint $table) {
            $table->id();
            $table->ulid('organization_id');
            $table->string('dedup_key', 255);
            $table->ulid('alert_id');
            $table->timestamp('first_alerted_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('resolved_at')->nullable();
            $table->unique(['organization_id', 'dedup_key']);
        });

        Schema::create('alerting_notifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('user_id');
            $table->ulid('alert_id')->nullable()->index();
            $table->string('type', 100);
            $table->string('severity', 16);
            $table->string('title', 500);
            $table->text('body')->nullable();
            $table->string('url', 2000)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->index();
            $table->index(['user_id', 'organization_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerting_notifications');
        Schema::dropIfExists('alerting_dedup_states');
        Schema::dropIfExists('alerting_deliveries');
        Schema::dropIfExists('alerting_rule_hits');
        Schema::dropIfExists('alerting_alerts');
        Schema::dropIfExists('alerting_rule_channels');
        Schema::dropIfExists('alerting_rules');
        Schema::dropIfExists('alerting_channels');
    }
};
