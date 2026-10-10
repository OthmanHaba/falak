<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Resource limits (Limits' ResourceLimits JSON): a slice per worker / daemon, shared by its instances.
        foreach (['processes_workers', 'processes_daemons'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->json('limits')->nullable()->after('env');
            });
        }
    }

    public function down(): void
    {
        foreach (['processes_workers', 'processes_daemons'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('limits');
            });
        }
    }
};
