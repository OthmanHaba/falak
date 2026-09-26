<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_firewall_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('server_id')->index();
            $table->string('name', 120);
            $table->string('action', 8);
            $table->string('protocol', 8);
            $table->string('port', 11)->nullable();
            $table->string('source', 64)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('network_firewall_states', function (Blueprint $table) {
            $table->ulid('server_id')->primary();
            $table->ulid('organization_id')->index();
            $table->unsignedInteger('revision')->default(0);
            $table->char('desired_hash', 64)->nullable();
            $table->char('applied_hash', 64)->nullable();
            $table->string('status', 16);
            $table->ulid('command_id')->nullable()->index();
            $table->char('ruleset_sha256', 64)->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        Schema::create('network_private_networks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('name', 64);
            $table->string('cidr', 18);
            $table->string('interface', 15)->unique();
            $table->unsignedInteger('listen_port');
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('network_private_network_members', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('network_id')->constrained('network_private_networks')->cascadeOnDelete();
            $table->ulid('organization_id')->index();
            $table->ulid('server_id')->index();
            $table->string('address', 15);
            $table->string('public_key', 44);
            $table->text('private_key')->nullable();
            $table->string('key_status', 16);
            $table->ulid('key_command_id')->nullable()->index();
            $table->string('status', 16);
            $table->ulid('command_id')->nullable()->index();
            $table->char('desired_hash', 64)->nullable();
            $table->char('applied_hash', 64)->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->string('error', 1000)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->unique(['network_id', 'server_id']);
            $table->unique(['network_id', 'address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_private_network_members');
        Schema::dropIfExists('network_private_networks');
        Schema::dropIfExists('network_firewall_states');
        Schema::dropIfExists('network_firewall_rules');
    }
};
