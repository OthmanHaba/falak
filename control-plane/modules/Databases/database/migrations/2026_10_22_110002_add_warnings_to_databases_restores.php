<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('databases_restores', function (Blueprint $table) {
            // A successful restore's warnings (db.restore warnings: e.g. a Redis config file the agent could not put
            // back, a dataset over the memory limit).
            $table->json('warnings')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('databases_restores', function (Blueprint $table) {
            $table->dropColumn('warnings');
        });
    }
};
