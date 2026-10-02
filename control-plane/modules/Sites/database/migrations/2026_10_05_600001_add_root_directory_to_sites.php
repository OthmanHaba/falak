<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites_sites', function (Blueprint $table) {
            // Repository subfolder the app lives in (monorepos): builds, the release and its hooks use it as the root.
            $table->string('root_directory')->nullable()->after('branch');
        });
    }

    public function down(): void
    {
        Schema::table('sites_sites', function (Blueprint $table) {
            $table->dropColumn('root_directory');
        });
    }
};
