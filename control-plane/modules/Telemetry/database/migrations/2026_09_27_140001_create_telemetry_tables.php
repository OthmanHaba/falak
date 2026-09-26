<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_settings', function (Blueprint $table) {
            $table->ulid('organization_id')->primary();
            $table->string('otlp_endpoint', 2048)->nullable();
            $table->text('otlp_token')->nullable();
            $table->string('environment', 64)->nullable();
            $table->decimal('traces_ratio', 5, 4)->nullable();
            $table->unsignedInteger('metrics_interval_s')->nullable();
            $table->timestamps();
        });

        Schema::create('telemetry_grafana_states', function (Blueprint $table) {
            $table->ulid('organization_id')->primary();
            $table->string('folder_uid', 40)->nullable();
            $table->json('dashboards')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->string('last_error', 2000)->nullable();
            $table->timestamps();
        });

        // Servers whose agent (re-)enrolled and still need telemetry.configure (see DispatchPendingTelemetry).
        Schema::create('telemetry_pending_configs', function (Blueprint $table) {
            $table->ulid('server_id')->primary();
            $table->ulid('organization_id')->index();
            $table->timestamp('due_at')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
        });

        Schema::create('telemetry_annotations', function (Blueprint $table) {
            $table->ulid('deployment_id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('site_id')->nullable();
            $table->unsignedBigInteger('grafana_id');
            $table->string('status', 32);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_annotations');
        Schema::dropIfExists('telemetry_pending_configs');
        Schema::dropIfExists('telemetry_grafana_states');
        Schema::dropIfExists('telemetry_settings');
    }
};
