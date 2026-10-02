<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The compose service whose domains are a compose site's own (compose_service null) as Edge last saw it: when the
 * first public service changes (reorder, split into its own site), its domains are handed over to the right service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_site_settings', fn (Blueprint $table) => $table->string('compose_primary', 63)->nullable()->after('encode'));
    }

    public function down(): void
    {
        Schema::table('edge_site_settings', fn (Blueprint $table) => $table->dropColumn('compose_primary'));
    }
};
