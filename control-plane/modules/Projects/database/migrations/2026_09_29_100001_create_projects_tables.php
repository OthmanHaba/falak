<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects_projects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('name', 64);
            $table->string('description', 500)->nullable();
            $table->string('icon', 32)->nullable();
            // The organization's fallback project (exactly one per organization).
            $table->boolean('is_default')->default(false);
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('projects_environments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('project_id')->constrained('projects_projects')->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('slug', 64);
            $table->boolean('is_production')->default(false);
            $table->ulid('forked_from_id')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'slug']);
        });

        // A site or database (opaque ULID of the owning module) placed on an environment's canvas.
        Schema::create('projects_services', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('project_id')->index();
            $table->foreignUlid('environment_id')->constrained('projects_environments')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->ulid('ref_id');
            $table->string('name', 64);
            $table->integer('x')->default(0);
            $table->integer('y')->default(0);
            $table->timestamps();
            $table->unique(['kind', 'ref_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects_services');
        Schema::dropIfExists('projects_environments');
        Schema::dropIfExists('projects_projects');
    }
};
