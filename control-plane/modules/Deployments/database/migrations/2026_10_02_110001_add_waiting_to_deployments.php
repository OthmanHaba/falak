<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deployments triggered while the site's servers are still being prepared wait (status `waiting`) instead of
 * failing; they start once every preparing server is ready, or fail after `deployments.waiting.timeout_minutes`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments_deployments', function (Blueprint $table) {
            $table->timestamp('waiting_since')->nullable()->after('requested_by');
            $table->string('waiting_reason', 1000)->nullable()->after('waiting_since');
        });
    }

    public function down(): void
    {
        Schema::table('deployments_deployments', function (Blueprint $table) {
            $table->dropColumn(['waiting_since', 'waiting_reason']);
        });
    }
};
