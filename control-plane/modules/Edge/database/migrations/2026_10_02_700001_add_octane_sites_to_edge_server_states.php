<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sites the last dispatched / the applied edge.caddy.apply reverse-proxy to Octane (Processes stops a switched-off
        // Octane only once neither does).
        Schema::table('edge_server_states', function (Blueprint $table) {
            $table->json('octane_sites')->nullable();
            $table->json('applied_octane_sites')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('edge_server_states', function (Blueprint $table) {
            $table->dropColumn(['octane_sites', 'applied_octane_sites']);
        });
    }
};
