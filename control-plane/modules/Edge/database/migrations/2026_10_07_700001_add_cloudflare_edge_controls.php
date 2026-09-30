<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cloudflare edge controls: cache mode per domain, Under Attack per zone (the level to return to), origin lock-down
 * per server (web ports closed behind a tunnel, or open to Cloudflare's ranges only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_domains', function (Blueprint $table) {
            $table->string('cloudflare_cache', 16)->nullable()->after('cloudflare_proxied'); // null = standard | everything | bypass
        });

        Schema::table('edge_cloudflare_zones', function (Blueprint $table) {
            $table->string('security_level_before', 32)->nullable()->after('proxied'); // set while Under Attack is on
        });

        Schema::create('edge_origin_locks', function (Blueprint $table) {
            $table->ulid('server_id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('mode', 16); // closed | cloudflare
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_origin_locks');
        Schema::table('edge_cloudflare_zones', fn (Blueprint $table) => $table->dropColumn('security_level_before'));
        Schema::table('edge_domains', fn (Blueprint $table) => $table->dropColumn('cloudflare_cache'));
    }
};
