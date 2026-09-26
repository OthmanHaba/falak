<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_dns_credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('provider', 32);
            $table->string('name');
            $table->text('api_token');
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('edge_certificates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('site_id')->nullable()->index();
            $table->string('name', 64)->unique();
            $table->json('domains');
            $table->text('cert_pem');
            $table->text('key_pem');
            $table->text('chain_pem')->nullable();
            $table->string('issuer')->nullable();
            $table->timestamp('not_before')->nullable();
            $table->timestamp('not_after')->nullable();
            $table->string('fingerprint', 64);
            $table->ulid('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('edge_certificate_installs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('certificate_id')->constrained('edge_certificates')->cascadeOnDelete();
            $table->ulid('server_id')->index();
            $table->ulid('command_id')->nullable()->index();
            $table->string('status', 16);
            $table->string('error', 1000)->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
            $table->unique(['certificate_id', 'server_id']);
        });

        Schema::create('edge_domains', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('site_id')->index();
            $table->string('name')->unique();
            $table->boolean('is_primary')->default(false);
            $table->string('www_redirect', 8)->default('none');
            $table->string('tls_mode', 16)->default('auto');
            $table->foreignUlid('certificate_id')->nullable()->constrained('edge_certificates')->nullOnDelete();
            $table->foreignUlid('dns_credential_id')->nullable()->constrained('edge_dns_credentials')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('edge_redirects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('site_id')->index();
            $table->string('from', 500);
            $table->string('to', 2000);
            $table->unsignedSmallInteger('status')->default(301);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('edge_security_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('site_id')->index();
            $table->string('name')->nullable();
            $table->string('path', 500)->nullable();
            $table->string('username', 100);
            $table->string('password_hash');
            $table->timestamps();
        });

        Schema::create('edge_headers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('site_id')->index();
            $table->string('name', 100);
            $table->string('value', 2000);
            $table->timestamps();
            $table->unique(['site_id', 'name']);
        });

        Schema::create('edge_site_settings', function (Blueprint $table) {
            $table->ulid('site_id')->primary();
            $table->json('allow_ips');
            $table->json('deny_ips');
            $table->unsignedBigInteger('max_body_bytes')->nullable();
            $table->boolean('encode')->default(true);
            $table->timestamps();
        });

        Schema::create('edge_load_balancers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('site_id')->unique();
            $table->ulid('server_id')->index();
            $table->string('policy', 16)->default('round_robin');
            $table->string('health_uri', 500)->nullable();
            $table->unsignedInteger('backend_port')->default(80);
            $table->json('weights');
            $table->timestamps();
        });

        Schema::create('edge_upstreams', function (Blueprint $table) {
            $table->id();
            $table->ulid('site_id');
            $table->ulid('server_id')->index();
            $table->string('upstream', 500);
            $table->timestamps();
            $table->unique(['site_id', 'server_id']);
        });

        Schema::create('edge_server_states', function (Blueprint $table) {
            $table->ulid('server_id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('payload_sha256', 64)->nullable();
            $table->ulid('command_id')->nullable()->index();
            $table->string('status', 16);
            $table->string('config_sha256', 64)->nullable();
            $table->unsignedInteger('routes')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['edge_server_states', 'edge_upstreams', 'edge_load_balancers', 'edge_site_settings', 'edge_headers', 'edge_security_rules', 'edge_redirects', 'edge_domains', 'edge_certificate_installs', 'edge_certificates', 'edge_dns_credentials'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
