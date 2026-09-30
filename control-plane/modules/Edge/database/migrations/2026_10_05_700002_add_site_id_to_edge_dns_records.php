<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compose sites route their public services' domains without edge_domains rows: their records belong to the site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_dns_records', function (Blueprint $table) {
            $table->ulid('site_id')->nullable()->index()->after('domain_id');
        });
    }

    public function down(): void
    {
        Schema::table('edge_dns_records', fn (Blueprint $table) => $table->dropColumn('site_id'));
    }
};
