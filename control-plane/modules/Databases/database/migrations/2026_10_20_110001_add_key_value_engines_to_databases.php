<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Redis and Valkey (v0.7.0): a server has one engine row per engine (a default app server: PostgreSQL and Redis), and
 * a key-value "database" is an instance with its own port and settings (maxmemory_mb, eviction, persistence).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('databases_servers', function (Blueprint $table) {
            $table->dropUnique(['server_id']);
            $table->index('server_id');
            $table->unique(['server_id', 'engine']);
        });

        Schema::table('databases_databases', function (Blueprint $table) {
            $table->unsignedInteger('port')->nullable()->after('collation');
            $table->json('settings')->nullable()->after('port');
        });
    }

    public function down(): void
    {
        Schema::table('databases_databases', function (Blueprint $table) {
            $table->dropColumn(['port', 'settings']);
        });

        Schema::table('databases_servers', function (Blueprint $table) {
            $table->dropUnique(['server_id', 'engine']);
            $table->dropIndex(['server_id']);
            $table->unique('server_id');
        });
    }
};
