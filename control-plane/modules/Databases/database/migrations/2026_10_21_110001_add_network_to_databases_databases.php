<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('databases_databases', function (Blueprint $table) {
            // Redis / Valkey network access (feature db.redis.network): what was last sent (`wanted`: bind addresses,
            // containers) and what the agent reported (`bind`, `container_host`, `skipped`).
            $table->json('network')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('databases_databases', function (Blueprint $table) {
            $table->dropColumn('network');
        });
    }
};
