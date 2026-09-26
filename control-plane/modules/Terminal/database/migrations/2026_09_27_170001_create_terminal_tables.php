<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminal_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('server_id')->index();
            $table->string('server_name');
            $table->ulid('user_id')->index();
            $table->string('unix_user', 32);
            $table->string('status', 16)->index();
            $table->ulid('command_id')->nullable()->unique();
            $table->unsignedSmallInteger('cols')->default(80);
            $table->unsignedSmallInteger('rows')->default(24);
            $table->unsignedSmallInteger('initial_cols')->default(80);
            $table->unsignedSmallInteger('initial_rows')->default(24);
            $table->unsignedInteger('idle_timeout_s');
            $table->boolean('shared')->default(false);
            // Part of the live channel name; rotated on unshare so existing watchers must re-authorize.
            $table->unsignedInteger('channel_epoch')->default(0);
            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('last_activity_at', 3)->nullable();
            $table->timestamp('closed_at', 3)->nullable();
            $table->string('close_reason', 32)->nullable();
            $table->integer('exit_code')->nullable();
            $table->string('error')->nullable();
            $table->unsignedBigInteger('recording_bytes')->default(0);
            $table->timestamps(3);
        });

        Schema::create('terminal_frames', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('session_id')->constrained('terminal_sessions')->cascadeOnDelete();
            $table->unsignedBigInteger('fleet_seq')->nullable();
            $table->char('kind', 1);
            $table->unsignedBigInteger('offset_ms');
            $table->text('data');
            $table->timestamp('created_at')->nullable();

            $table->unique(['session_id', 'fleet_seq']);
            $table->index(['session_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_frames');
        Schema::dropIfExists('terminal_sessions');
    }
};
