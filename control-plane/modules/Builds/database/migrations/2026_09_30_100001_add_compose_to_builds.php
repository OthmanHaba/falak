<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compose builds (docker mode): the repository's compose file and the digest-pinned image of every `build:` service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builds_builds', function (Blueprint $table) {
            $table->json('compose')->nullable()->after('manifest');
        });
    }

    public function down(): void
    {
        Schema::table('builds_builds', function (Blueprint $table) {
            $table->dropColumn('compose');
        });
    }
};
