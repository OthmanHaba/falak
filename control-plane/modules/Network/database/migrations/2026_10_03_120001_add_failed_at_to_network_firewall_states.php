<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the server's firewall started failing to apply (null while it applies fine): alerts fire on the transitions
 * only — the first failure, and the first success after it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_firewall_states', function (Blueprint $table) {
            $table->timestamp('failed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('network_firewall_states', function (Blueprint $table) {
            $table->dropColumn('failed_at');
        });
    }
};
