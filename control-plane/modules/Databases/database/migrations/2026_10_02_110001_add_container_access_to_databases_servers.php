<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('databases_servers', function (Blueprint $table) {
            // Containers on the server reach this (localhost) engine: users applied with the Docker ranges, the
            // firewall open on the Docker bridges. Set once the agent supports it (feature db.containers).
            $table->boolean('container_access')->default(false)->after('dedicated');
        });
    }

    public function down(): void
    {
        Schema::table('databases_servers', function (Blueprint $table) {
            $table->dropColumn('container_access');
        });
    }
};
