<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects_environments', function (Blueprint $table) {
            // A pull request's preview (Previews owns the lifecycle): forked from its base environment.
            $table->boolean('is_preview')->default(false);
            // The pull request comes from a fork: untrusted code, no secrets at all.
            $table->boolean('is_fork_preview')->default(false);
            // Service names a preview resolves from its base environment (`${{ api.URL }}` of a service it doesn't run).
            $table->json('shared_services')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects_environments', function (Blueprint $table) {
            $table->dropColumn(['is_preview', 'is_fork_preview', 'shared_services']);
        });
    }
};
