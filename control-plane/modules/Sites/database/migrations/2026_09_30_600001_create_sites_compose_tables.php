<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Docker Compose sites (docs/COMPOSE_TEMPLATES.md §1): source + public services + template metadata on the
 * site, versioned inline compose files, the last reported state of each server's compose project and the
 * organization's compose policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites_sites', function (Blueprint $table) {
            $table->string('compose_source', 8)->nullable()->after('compose_file');
            $table->json('public_services')->nullable()->after('compose_source');
            $table->json('template')->nullable()->after('public_services');
        });

        Schema::create('sites_compose_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('sites_sites')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('content');
            $table->ulid('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['site_id', 'version']);
        });

        Schema::create('sites_compose_states', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('sites_sites')->cascadeOnDelete();
            $table->ulid('server_id');
            $table->json('services');
            $table->ulid('command_id')->nullable()->index();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'server_id']);
        });

        Schema::create('sites_organization_settings', function (Blueprint $table) {
            $table->ulid('organization_id')->primary();
            $table->boolean('allow_privileged_compose')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites_organization_settings');
        Schema::dropIfExists('sites_compose_states');
        Schema::dropIfExists('sites_compose_versions');

        Schema::table('sites_sites', function (Blueprint $table) {
            $table->dropColumn(['compose_source', 'public_services', 'template']);
        });
    }
};
