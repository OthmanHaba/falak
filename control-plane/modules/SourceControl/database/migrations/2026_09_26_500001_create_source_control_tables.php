<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_control_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('provider', 16);
            $table->string('name');
            $table->string('auth_type', 16);
            $table->string('base_url')->nullable();
            $table->string('account')->nullable();
            $table->text('credentials')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('source_control_deploy_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('connection_id')->constrained('source_control_connections')->cascadeOnDelete();
            $table->string('repository');
            $table->string('title');
            $table->text('public_key');
            $table->text('private_key');
            $table->string('fingerprint', 128);
            $table->string('provider_key_id')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->string('install_error', 1000)->nullable();
            $table->timestamps();
            $table->index(['connection_id', 'repository']);
        });

        Schema::create('source_control_webhooks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('connection_id')->constrained('source_control_connections')->cascadeOnDelete();
            $table->string('repository');
            $table->string('provider_hook_id')->nullable();
            $table->text('secret');
            $table->boolean('installed')->default(false);
            $table->string('install_error', 1000)->nullable();
            $table->timestamp('last_delivery_at')->nullable();
            $table->timestamps();
            $table->unique(['connection_id', 'repository']);
        });

        Schema::create('source_control_pushes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('connection_id')->constrained('source_control_connections')->cascadeOnDelete();
            $table->ulid('webhook_id')->nullable();
            $table->string('repository');
            $table->string('branch');
            $table->string('sha', 64);
            $table->string('before_sha', 64)->nullable();
            $table->string('author_name')->nullable();
            $table->string('author_email')->nullable();
            $table->text('message');
            $table->string('url', 1000)->nullable();
            $table->string('pusher')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('received_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_control_pushes');
        Schema::dropIfExists('source_control_webhooks');
        Schema::dropIfExists('source_control_deploy_keys');
        Schema::dropIfExists('source_control_connections');
    }
};
