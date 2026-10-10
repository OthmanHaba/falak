<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The instance's preview domain (one row): pull request previews are served as <label>.<domain>. With a Cloudflare
 * credential Falak keeps `*.<domain>` pointing at the preview edge server, which holds a DNS-01 wildcard certificate;
 * previews on other servers get a record per host (edge_preview_records).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_preview_domain', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // The operator's organization: its DNS credential and edge server.
            $table->ulid('organization_id')->index();
            $table->string('domain', 200);
            $table->ulid('dns_credential_id')->nullable();
            $table->ulid('server_id');
            $table->string('zone_id', 64)->nullable();
            $table->string('record_id', 64)->nullable();
            // pending | active | manual (the user keeps the wildcard record) | error
            $table->string('status', 16)->default('pending');
            $table->string('error', 1000)->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('edge_preview_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('site_id')->index();
            $table->string('host', 253)->unique();
            $table->string('zone_id', 64);
            $table->string('record_id', 64)->nullable();
            $table->string('content', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_preview_records');
        Schema::dropIfExists('edge_preview_domain');
    }
};
