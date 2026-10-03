<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which engine engine_command_id installs: a database engine (null, as before) or a cache engine (Redis / Valkey).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers_servers', function (Blueprint $table) {
            $table->string('engine_install_kind', 16)->nullable()->after('engine_command_id');
        });
    }

    public function down(): void
    {
        Schema::table('servers_servers', function (Blueprint $table) {
            $table->dropColumn('engine_install_kind');
        });
    }
};
