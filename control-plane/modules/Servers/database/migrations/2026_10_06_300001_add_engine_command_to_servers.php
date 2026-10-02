<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A database engine added to a provisioned server: the provision.apply command installing it (null when none is
 * pending), so its outcome registers the engine or takes it back out of the stack.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers_servers', function (Blueprint $table) {
            $table->string('engine_command_id')->nullable()->after('provision_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('servers_servers', function (Blueprint $table) {
            $table->dropColumn('engine_command_id');
        });
    }
};
