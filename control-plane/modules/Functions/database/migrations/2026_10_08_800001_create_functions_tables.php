<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A function is a site with the `function` runtime; this is its code and how it scales (docs/plans/FUNCTIONS.md).
        Schema::create('functions_functions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('site_id')->unique();
            $table->string('runtime', 32);
            $table->string('entrypoint', 200);
            $table->unsignedSmallInteger('min_instances');
            $table->unsignedSmallInteger('max_instances');
            $table->unsignedInteger('concurrency');
            $table->unsignedInteger('idle_timeout_s');
            $table->unsignedInteger('memory_mb');
            $table->decimal('cpus', 5, 2);
            $table->unsignedInteger('request_timeout_s');
            $table->timestamps();
        });

        // Immutable: every deploy of changed code adds one. The hash (files + entrypoint) is the deployment's commit.
        Schema::create('functions_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('function_id');
            $table->unsignedInteger('number');
            $table->longText('files');
            $table->string('entrypoint', 200);
            $table->char('hash', 64);
            $table->unsignedInteger('size');
            $table->string('message', 500)->nullable();
            $table->ulid('author_id')->nullable();
            $table->string('author_name')->nullable();
            $table->ulid('base_version_id')->nullable();
            $table->timestamp('created_at');

            $table->unique(['function_id', 'number']);
            $table->index(['function_id', 'hash']);
        });

        // Unsaved editor work, one per user and function.
        Schema::create('functions_drafts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('function_id');
            $table->ulid('user_id');
            $table->longText('files');
            $table->ulid('base_version_id')->nullable();
            $table->timestamps();

            $table->unique(['function_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('functions_drafts');
        Schema::dropIfExists('functions_versions');
        Schema::dropIfExists('functions_functions');
    }
};
