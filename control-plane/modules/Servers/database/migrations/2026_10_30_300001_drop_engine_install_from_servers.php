<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.10: databases are containers (Databases), so servers no longer install engines after creation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers_servers', function (Blueprint $table) {
            $table->dropColumn(['engine_command_id', 'engine_install_kind']);
        });
    }

    public function down(): void
    {
        Schema::table('servers_servers', function (Blueprint $table) {
            $table->string('engine_command_id')->nullable()->after('provision_attempts');
            $table->string('engine_install_kind', 16)->nullable()->after('engine_command_id');
        });
    }
};
