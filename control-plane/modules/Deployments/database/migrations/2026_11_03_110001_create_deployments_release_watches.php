<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rollback after a release goes live: per-site watch settings (opt-in), one watch window per successful
 * deployment, and the reason a deployment's release was rolled back automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments_site_settings', function (Blueprint $table) {
            $table->boolean('watch_enabled')->default(false)->after('secrets_mode');
            $table->unsignedSmallInteger('watch_minutes')->default(5)->after('watch_enabled');
            $table->boolean('watch_health')->default(true)->after('watch_minutes');
            $table->unsignedSmallInteger('watch_health_failures')->default(3)->after('watch_health');
            $table->boolean('watch_crashes')->default(true)->after('watch_health_failures');
            $table->boolean('watch_errors')->default(true)->after('watch_crashes');
            $table->boolean('watch_issues')->default(false)->after('watch_errors');
            $table->string('watch_on_trigger', 16)->default('rollback')->after('watch_issues');
        });

        Schema::table('deployments_deployments', function (Blueprint $table) {
            // The deployment whose release this (rollback) deployment replaces after its watch tripped.
            $table->string('auto_rollback_of', 26)->nullable()->after('target_release_id');
            $table->text('rolled_back_reason')->nullable()->after('rolled_back');
            $table->timestamp('rolled_back_at')->nullable()->after('rolled_back_reason');
        });

        Schema::table('deployments_releases', function (Blueprint $table) {
            // Never rolled back to automatically again.
            $table->timestamp('auto_rolled_back_at')->nullable()->after('activated_at');
        });

        Schema::create('deployments_release_watches', function (Blueprint $table) {
            $table->string('deployment_id', 26)->primary();
            $table->string('organization_id', 26)->index();
            $table->string('site_id', 26);
            $table->string('release_id', 26);
            $table->string('previous_release_id', 26)->nullable();
            // watching | passed | rolled_back | alerted | stopped
            $table->string('status', 16);
            $table->json('triggers');
            $table->string('on_trigger', 16);
            $table->boolean('migrations')->default(false);
            $table->json('baseline')->nullable();
            $table->json('checks')->nullable();
            $table->unsignedSmallInteger('health_failures')->default(0);
            $table->string('trigger', 16)->nullable();
            $table->text('reason')->nullable();
            $table->string('rollback_deployment_id', 26)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ends_at');
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status']);
            $table->index(['status', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments_release_watches');

        Schema::table('deployments_releases', function (Blueprint $table) {
            $table->dropColumn('auto_rolled_back_at');
        });

        Schema::table('deployments_deployments', function (Blueprint $table) {
            $table->dropColumn(['auto_rollback_of', 'rolled_back_reason', 'rolled_back_at']);
        });

        Schema::table('deployments_site_settings', function (Blueprint $table) {
            $table->dropColumn(['watch_enabled', 'watch_minutes', 'watch_health', 'watch_health_failures', 'watch_crashes', 'watch_errors', 'watch_issues', 'watch_on_trigger']);
        });
    }
};
