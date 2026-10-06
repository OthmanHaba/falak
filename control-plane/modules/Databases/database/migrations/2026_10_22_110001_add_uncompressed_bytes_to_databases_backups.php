<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('databases_backups', function (Blueprint $table) {
            // The dump's size before compression (db.backup uncompressed_bytes): a Redis / Valkey restore checks the
            // instance's free disk space against it. Null for backups of older agents.
            $table->unsignedBigInteger('uncompressed_bytes')->nullable()->after('size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('databases_backups', function (Blueprint $table) {
            $table->dropColumn('uncompressed_bytes');
        });
    }
};
