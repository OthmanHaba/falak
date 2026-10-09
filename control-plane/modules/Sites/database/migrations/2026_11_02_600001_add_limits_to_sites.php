<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites_sites', function (Blueprint $table) {
            // Resource limits (Limits' ResourceLimits): the site's container, or its slice on hosts (PHP-FPM, Octane,
            // the web process); compose_limits per compose service name.
            $table->json('limits')->nullable()->after('health_check_path');
            $table->json('compose_limits')->nullable()->after('compose_services');
        });
    }

    public function down(): void
    {
        Schema::table('sites_sites', function (Blueprint $table) {
            $table->dropColumn(['limits', 'compose_limits']);
        });
    }
};
