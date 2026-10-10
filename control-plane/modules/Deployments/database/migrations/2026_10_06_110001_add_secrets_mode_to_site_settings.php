<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a container site gets its secret variables: `env` (environment variables, as before) or `files`
 * (/run/secrets/<NAME>, which `docker inspect` doesn't show).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments_site_settings', function (Blueprint $table) {
            $table->string('secrets_mode', 8)->default('env')->after('health_retry_delay_s');
        });
    }

    public function down(): void
    {
        Schema::table('deployments_site_settings', function (Blueprint $table) {
            $table->dropColumn('secrets_mode');
        });
    }
};
