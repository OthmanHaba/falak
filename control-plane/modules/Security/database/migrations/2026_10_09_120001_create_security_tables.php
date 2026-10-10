<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_audits', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('server_id');
            $table->string('status', 16);
            $table->string('trigger', 16);
            $table->ulid('command_id')->nullable()->index();
            $table->unsignedTinyInteger('score')->nullable();
            // {"pass": n, "warn": n, "fail": n, "info": n, "critical": n, "high": n, ...} (failures per severity)
            $table->json('counts')->nullable();
            $table->boolean('production_ready')->default(false);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error', 1000)->nullable();
            $table->ulid('requested_by')->nullable();
            $table->timestamp('ran_at')->nullable();
            $table->timestamps();
            $table->index(['server_id', 'created_at']);
        });

        Schema::create('security_findings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('audit_id')->constrained('security_audits')->cascadeOnDelete();
            $table->ulid('organization_id')->index();
            $table->ulid('server_id')->index();
            $table->string('check_id', 120);
            $table->string('title', 200);
            $table->string('area', 16);
            $table->string('status', 8);
            $table->string('severity', 8);
            $table->string('evidence', 600);
            $table->string('fix_id', 120)->nullable();
            $table->boolean('disruptive')->default(false);
            $table->unique(['audit_id', 'check_id']);
        });

        Schema::create('security_fixes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('server_id');
            $table->string('fix_id', 120);
            $table->string('status', 16);
            $table->boolean('disruptive')->default(false);
            $table->boolean('undoable')->default(false);
            $table->ulid('command_id')->nullable()->index();
            $table->ulid('undo_command_id')->nullable()->index();
            // The agent's backup id, or the firewall rule a control-plane fix created.
            $table->string('backup_id', 64)->nullable();
            // Fixes queued by "Fix all safe" run one after another (batch, position).
            $table->ulid('batch_id')->nullable()->index();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('message', 1000)->nullable();
            $table->string('error', 1000)->nullable();
            $table->ulid('applied_by')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->ulid('undone_by')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
            $table->index(['server_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_fixes');
        Schema::dropIfExists('security_findings');
        Schema::dropIfExists('security_audits');
    }
};
