<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // User-created canvas groups (UI_DESIGN §4.3). A group has an anchor (x, y); the positions of its services are
        // relative to that anchor, and the frame drawn around them is derived from their bounding box.
        Schema::create('projects_groups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('project_id')->index();
            $table->foreignUlid('environment_id')->constrained('projects_environments')->cascadeOnDelete();
            $table->string('name', 64);
            $table->integer('x')->default(0);
            $table->integer('y')->default(0);
            $table->boolean('collapsed')->default(false);
            $table->timestamps();
        });

        Schema::table('projects_services', function (Blueprint $table) {
            $table->ulid('group_id')->nullable()->index();
            // Canvas layout owned by the card itself: compose sites keep their compose services' positions (relative to
            // the service's x/y) and whether the group is collapsed.
            $table->json('layout')->nullable();
        });

        // Starred projects, per user (pinned first on the Projects dashboard).
        Schema::create('projects_favorites', function (Blueprint $table) {
            $table->ulid('user_id');
            $table->foreignUlid('project_id')->constrained('projects_projects')->cascadeOnDelete();
            $table->ulid('organization_id')->index();
            $table->timestamp('created_at')->nullable();
            $table->primary(['user_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects_favorites');
        Schema::table('projects_services', function (Blueprint $table) {
            $table->dropIndex(['group_id']);
            $table->dropColumn(['group_id', 'layout']);
        });
        Schema::dropIfExists('projects_groups');
    }
};
