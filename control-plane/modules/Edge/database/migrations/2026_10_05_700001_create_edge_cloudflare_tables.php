<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cloudflare integration: a DNS credential (provider cloudflare) is the connection; zones enabled on it get their DNS
 * records managed by Kiln (edge_dns_records tracks only the records Kiln created, so nothing else is ever touched).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_dns_credentials', function (Blueprint $table) {
            $table->string('account_id', 64)->nullable()->after('api_token');
            $table->timestamp('verified_at')->nullable()->after('account_id');
        });

        Schema::create('edge_cloudflare_zones', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('dns_credential_id')->constrained('edge_dns_credentials')->cascadeOnDelete();
            $table->string('zone_id', 64);
            $table->string('name');
            $table->boolean('proxied')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('edge_dns_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('domain_id')->nullable()->index();
            $table->foreignUlid('zone_id')->constrained('edge_cloudflare_zones')->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 8);
            $table->string('content');
            $table->boolean('proxied')->default(true);
            $table->string('record_id', 64)->nullable();
            $table->string('status', 16)->default('pending'); // pending | synced | conflict | error
            $table->text('error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['zone_id', 'name', 'type', 'content']);
        });

        Schema::table('edge_domains', function (Blueprint $table) {
            // null = the zone's default; true/false = this domain's own choice (orange / grey cloud).
            $table->boolean('cloudflare_proxied')->nullable()->after('dns_credential_id');
        });
    }

    public function down(): void
    {
        Schema::table('edge_domains', fn (Blueprint $table) => $table->dropColumn('cloudflare_proxied'));
        Schema::dropIfExists('edge_dns_records');
        Schema::dropIfExists('edge_cloudflare_zones');
        Schema::table('edge_dns_credentials', fn (Blueprint $table) => $table->dropColumn(['account_id', 'verified_at']));
    }
};
