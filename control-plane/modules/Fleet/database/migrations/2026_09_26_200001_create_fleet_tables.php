<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_certificate_authorities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->text('certificate_pem');
            $table->text('private_key');
            $table->string('fingerprint', 64)->unique();
            $table->timestamp('not_before');
            $table->timestamp('not_after');
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('fleet_install_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('server_id')->nullable()->index();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->ulid('agent_id')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('fleet_agents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('server_id')->nullable()->index();
            $table->string('status', 16)->index();
            $table->string('hostname')->nullable();
            $table->string('arch', 16)->nullable();
            $table->string('agent_version', 64)->nullable();
            $table->json('facts')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamp('enrolled_at');
            $table->timestamp('last_heartbeat_at')->nullable()->index();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('fleet_certificates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agent_id')->constrained('fleet_agents')->cascadeOnDelete();
            $table->string('serial', 64)->unique();
            $table->string('fingerprint', 64)->unique();
            $table->text('certificate_pem');
            $table->timestamp('not_before');
            $table->timestamp('not_after');
            $table->timestamp('first_used_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('fleet_commands', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('agent_id')->constrained('fleet_agents')->cascadeOnDelete();
            $table->ulid('server_id')->index();
            $table->string('type', 64);
            $table->longText('payload');
            $table->unsignedInteger('timeout_s');
            $table->string('idempotency_key');
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->integer('exit_code')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('queued_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['agent_id', 'status']);
            $table->index(['agent_id', 'idempotency_key']);
            $table->index(['server_id', 'created_at']);
        });

        Schema::create('fleet_command_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('command_id')->constrained('fleet_commands')->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->string('kind', 16);
            $table->string('stream', 8)->nullable();
            $table->longText('data')->nullable();
            $table->float('progress')->nullable();
            $table->integer('exit_code')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('at');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['command_id', 'seq']);
        });

        Schema::create('fleet_agent_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('agent_id')->constrained('fleet_agents')->cascadeOnDelete();
            $table->ulid('server_id')->nullable();
            $table->timestamp('at');
            $table->unsignedBigInteger('uptime_s');
            $table->float('load1');
            $table->float('load5');
            $table->float('load15');
            $table->float('cpu_percent')->nullable();
            $table->unsignedBigInteger('memory_used_bytes');
            $table->unsignedBigInteger('disk_used_bytes');
            $table->index(['server_id', 'at']);
            $table->index(['agent_id', 'at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_agent_metrics');
        Schema::dropIfExists('fleet_command_events');
        Schema::dropIfExists('fleet_commands');
        Schema::dropIfExists('fleet_certificates');
        Schema::dropIfExists('fleet_agents');
        Schema::dropIfExists('fleet_install_tokens');
        Schema::dropIfExists('fleet_certificate_authorities');
    }
};
