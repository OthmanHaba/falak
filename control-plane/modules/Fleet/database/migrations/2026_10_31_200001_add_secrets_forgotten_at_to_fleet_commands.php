<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a command's payload secrets (backup keys, an age identity) were forgotten: the sweep skips it from then on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_commands', function (Blueprint $table) {
            $table->timestamp('secrets_forgotten_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fleet_commands', function (Blueprint $table) {
            $table->dropColumn('secrets_forgotten_at');
        });
    }
};
