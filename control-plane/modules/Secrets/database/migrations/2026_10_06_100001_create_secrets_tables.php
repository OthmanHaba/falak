<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secrets_secrets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            // organization | project | environment | service (a project service id); the nearest scope wins.
            $table->string('scope_type', 16);
            $table->ulid('scope_id');
            $table->string('name', 255);
            $table->string('kind', 16)->default('managed');
            // Linked secrets: the provider their reference is resolved with.
            $table->ulid('provider_id')->nullable();
            $table->boolean('sensitive')->default(true);
            $table->boolean('available_to_previews')->default(false);
            $table->string('description', 1000)->nullable();
            $table->unsignedSmallInteger('rotation_days')->nullable();
            $table->unsignedInteger('current_version')->default(0);
            $table->timestamp('last_accessed_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['scope_type', 'scope_id', 'name']);
        });

        // Immutable: a new value, or a rollback, is a new version.
        Schema::create('secrets_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('secret_id')->constrained('secrets_secrets')->cascadeOnDelete();
            $table->unsignedInteger('version');
            // Sealed under the organization's data key and bound to (organization, secret, version). Linked
            // secrets hold their reference here instead of a value.
            $table->text('ciphertext');
            $table->unsignedInteger('restored_from')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('disabled_at')->nullable();
            $table->unique(['secret_id', 'version']);
        });

        // Outlives the secret (hence the copy of its name and no foreign key).
        Schema::create('secrets_access_log', function (Blueprint $table) {
            $table->id();
            $table->ulid('organization_id');
            $table->ulid('secret_id');
            $table->string('secret_name', 255);
            $table->unsignedInteger('version');
            $table->string('actor_type', 16);
            $table->string('actor_id', 64)->nullable();
            $table->ulid('user_id')->nullable();
            $table->string('reason', 255);
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->index();
            $table->index(['secret_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['secret_id', 'version', 'actor_type', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secrets_access_log');
        Schema::dropIfExists('secrets_versions');
        Schema::dropIfExists('secrets_secrets');
    }
};
