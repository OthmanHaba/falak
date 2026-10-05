<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Docker sites used app_port both as the port the app listens on inside its container and as the loopback host port,
 * which had to be unique per server, so two images listening on 3000 could not share a server. container_port is now the
 * in-container port (free to repeat); app_port stays the Falak-allocated host port. Existing Docker sites keep their
 * behaviour: container_port = app_port.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites_sites', function (Blueprint $table) {
            $table->unsignedInteger('container_port')->nullable()->after('app_port');
        });

        DB::table('sites_sites')->where('runtime', 'docker')->update(['container_port' => DB::raw('app_port')]);
    }

    public function down(): void
    {
        Schema::table('sites_sites', function (Blueprint $table) {
            $table->dropColumn('container_port');
        });
    }
};
