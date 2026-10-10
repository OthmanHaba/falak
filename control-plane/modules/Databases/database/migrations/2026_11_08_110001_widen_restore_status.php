<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Point-in-time restores wait in `awaiting_decision` (17 characters): the column was 16 wide, so on Postgres and
 * MySQL settling a restore failed ("value too long") and it stayed pending. SQLite ignores the length.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('databases_restores', function (Blueprint $table) {
            $table->string('status', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('databases_restores', function (Blueprint $table) {
            $table->string('status', 16)->change();
        });
    }
};
