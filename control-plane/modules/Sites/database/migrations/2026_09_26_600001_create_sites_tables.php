<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites_sites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('name');
            $table->string('slug', 63)->unique();
            $table->string('runtime', 16);
            $table->string('build_mode', 16);
            $table->string('framework', 16);
            $table->string('php_version', 8)->nullable();
            $table->string('node_version', 8)->nullable();
            $table->ulid('source_connection_id')->nullable()->index();
            $table->string('repository')->nullable();
            $table->string('branch')->nullable();
            $table->ulid('deploy_key_id')->nullable();
            $table->boolean('push_to_deploy')->default(false);
            $table->string('web_directory')->default('');
            $table->string('unix_user', 32);
            $table->boolean('isolated')->default(false);
            $table->unsignedInteger('app_port')->nullable();
            $table->string('docker_image')->nullable();
            $table->string('dockerfile')->nullable();
            $table->string('compose_file')->nullable();
            $table->string('health_check_path')->nullable();
            $table->text('deploy_script');
            $table->json('laravel');
            $table->json('shared_paths');
            $table->boolean('test_domain_enabled')->default(true);
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
            $table->index(['source_connection_id', 'repository', 'branch']);
        });

        Schema::create('sites_targets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('sites_sites')->cascadeOnDelete();
            $table->ulid('server_id')->index();
            $table->string('role', 16);
            $table->string('status', 16);
            $table->string('status_message', 1000)->nullable();
            $table->string('step', 16)->nullable();
            $table->ulid('command_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['site_id', 'server_id']);
        });

        Schema::create('sites_environment_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('sites_sites')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('variables');
            $table->json('exposed');
            $table->json('changed_keys');
            $table->ulid('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['site_id', 'version']);
        });

        Schema::create('sites_commands', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('sites_sites')->cascadeOnDelete();
            $table->ulid('server_id');
            $table->string('command', 2000);
            $table->string('unix_user', 32);
            $table->ulid('command_id')->nullable()->index();
            $table->string('status', 16);
            $table->integer('exit_code')->nullable();
            $table->ulid('requested_by')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites_commands');
        Schema::dropIfExists('sites_environment_versions');
        Schema::dropIfExists('sites_targets');
        Schema::dropIfExists('sites_sites');
    }
};
