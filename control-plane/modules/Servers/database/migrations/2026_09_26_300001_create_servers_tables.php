<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers_servers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('name');
            $table->string('type', 16);
            $table->string('status', 16)->index();
            $table->string('status_message', 1000)->nullable();
            $table->string('provider', 32);
            $table->ulid('provider_credential_id')->nullable()->index();
            $table->string('provider_server_id')->nullable();
            $table->string('region')->nullable();
            $table->string('size')->nullable();
            $table->string('image')->nullable();
            $table->string('ipv4', 45)->nullable();
            $table->string('ipv6', 45)->nullable();
            $table->string('private_ipv4', 45)->nullable();
            $table->unsignedInteger('ssh_port')->default(22);
            $table->string('timezone', 64)->default('UTC');
            $table->json('stack');
            $table->json('facts')->nullable();
            $table->string('os')->nullable();
            $table->string('arch', 16)->nullable();
            $table->unsignedInteger('cpus')->nullable();
            $table->unsignedBigInteger('memory_bytes')->nullable();
            $table->unsignedBigInteger('disk_bytes')->nullable();
            $table->text('install_command')->nullable();
            $table->ulid('provision_command_id')->nullable()->index();
            $table->unsignedSmallInteger('provision_attempts')->default(0);
            $table->ulid('ssh_sync_command_id')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('servers_php_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained('servers_servers')->cascadeOnDelete();
            $table->string('version', 8);
            $table->string('status', 16);
            $table->boolean('is_default')->default(false);
            $table->json('ini');
            $table->json('fpm');
            $table->ulid('command_id')->nullable()->index();
            $table->string('status_message', 1000)->nullable();
            $table->timestamps();
            $table->unique(['server_id', 'version']);
        });

        Schema::create('servers_ssh_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('user_id')->nullable();
            $table->string('name');
            $table->text('public_key');
            $table->string('fingerprint', 128);
            $table->timestamps();
            $table->unique(['organization_id', 'fingerprint']);
        });

        Schema::create('servers_server_ssh_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('server_id')->constrained('servers_servers')->cascadeOnDelete();
            $table->foreignUlid('ssh_key_id')->constrained('servers_ssh_keys')->cascadeOnDelete();
            $table->string('unix_user', 32);
            $table->timestamps();
            $table->unique(['server_id', 'ssh_key_id', 'unix_user']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers_server_ssh_keys');
        Schema::dropIfExists('servers_ssh_keys');
        Schema::dropIfExists('servers_php_versions');
        Schema::dropIfExists('servers_servers');
    }
};
