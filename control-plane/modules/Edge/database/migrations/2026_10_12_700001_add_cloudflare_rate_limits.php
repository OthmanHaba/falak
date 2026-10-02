<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rate limits through Cloudflare (Kiln's edge, stock Caddy, has none): a rule per proxied domain, compiled into the
 * zone's http_ratelimit rules, and the zone's plan (what Cloudflare allows: rules, fields, periods).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_domains', function (Blueprint $table) {
            // {path: ?string, requests: int, period: int, action: block|managed_challenge, timeout: int}
            $table->json('cloudflare_rate_limit')->nullable()->after('cloudflare_cache');
        });
        Schema::table('edge_cloudflare_zones', function (Blueprint $table) {
            $table->string('plan', 32)->nullable()->after('proxied');
            // Kiln has rate limit rules in the zone (domain removals re-sync only then).
            $table->boolean('rate_limited')->default(false)->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('edge_domains', fn (Blueprint $table) => $table->dropColumn('cloudflare_rate_limit'));
        Schema::table('edge_cloudflare_zones', fn (Blueprint $table) => $table->dropColumn(['plan', 'rate_limited']));
    }
};
