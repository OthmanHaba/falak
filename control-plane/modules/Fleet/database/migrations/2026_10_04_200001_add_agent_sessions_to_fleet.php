<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent sessions: every kiln-agent process sends a random session id (X-Kiln-Agent-Session). A command remembers
 * the session it was delivered to, so commands delivered to a process that has since restarted are redelivered
 * (or failed) instead of staying "delivered" forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_agents', function (Blueprint $table) {
            $table->string('session_id', 64)->nullable();
            $table->timestamp('session_started_at')->nullable();
        });

        Schema::table('fleet_commands', function (Blueprint $table) {
            $table->string('delivered_session', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fleet_commands', function (Blueprint $table) {
            $table->dropColumn('delivered_session');
        });

        Schema::table('fleet_agents', function (Blueprint $table) {
            $table->dropColumn(['session_id', 'session_started_at']);
        });
    }
};
