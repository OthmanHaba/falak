<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            // Instance ports never repeat on a server (NULL — SQL databases — never collides).
            $table->unique(['server_id', 'port']);
        });
    }

    public function down(): void
    {
        // One row per server again: Redis / Valkey rows (and their instances) can't be kept, and dropping them
        // silently would forget instances still running on servers.
        if (DB::table('databases_servers')->whereIn('engine', ['redis', 'valkey'])->exists()) {
            throw new RuntimeException('Delete the Redis and Valkey instances and engines under Databases before rolling this migration back.');
        }

        Schema::table('databases_databases', function (Blueprint $table) {
            $table->dropUnique(['server_id', 'port']);
            $table->dropColumn(['port', 'settings']);
        });

        Schema::table('databases_servers', function (Blueprint $table) {
            $table->dropUnique(['server_id', 'engine']);
            $table->dropIndex(['server_id']);
            $table->unique('server_id');
        });
    }
};
