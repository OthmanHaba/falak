<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A project's preview settings (one row per project).
        Schema::create('previews_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('project_id')->unique();
            $table->boolean('enabled')->default(false);
            $table->ulid('base_environment_id')->nullable();
            // {service name: include | share | omit}; services not listed are included
            $table->json('services')->nullable();
            // null: the base environment's servers (the first site's leader)
            $table->ulid('server_id')->nullable();
            $table->string('domain_pattern', 100)->default('pr-{number}-{service}');
            // {database service name: {strategy: empty|clone_backup|clone_sanitize, source_environment_id?, sanitize_kind?, sanitize_script?}}
            $table->json('databases')->nullable();
            $table->unsignedSmallInteger('max_concurrent')->default(5);
            $table->unsignedSmallInteger('idle_ttl_hours')->default(72);
            // basic | public
            $table->string('access', 16)->default('basic');
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
        });

        // One preview per project and pull request.
        Schema::create('previews_previews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('project_id')->index();
            $table->ulid('connection_id');
            $table->string('provider', 16);
            $table->string('repository');
            $table->unsignedInteger('number');
            $table->string('title', 250)->default('');
            $table->string('url', 1000)->nullable();
            $table->string('author', 250)->nullable();
            $table->string('head_branch', 250);
            $table->string('head_sha', 64);
            $table->string('base_branch', 250)->default('');
            $table->boolean('is_fork')->default(false);
            $table->string('source_repository')->nullable();
            // waiting_approval | queued | creating | deploying | ready | failed | closed
            $table->string('status', 24)->index();
            $table->string('status_message', 1000)->nullable();
            $table->ulid('environment_id')->nullable()->index();
            // {service name: site id}
            $table->json('sites')->nullable();
            // {service name: {database_id, strategy, state, restore_id?, command_id?}}
            $table->json('databases')->nullable();
            // {service name: url}
            $table->json('urls')->nullable();
            // {site id: deployed | failed | deploying}
            $table->json('deployments')->nullable();
            $table->string('deployed_sha', 64)->nullable();
            $table->string('comment_id', 64)->nullable();
            $table->string('basic_username', 64)->nullable();
            $table->text('basic_password')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'connection_id', 'repository', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('previews_previews');
        Schema::dropIfExists('previews_settings');
    }
};
