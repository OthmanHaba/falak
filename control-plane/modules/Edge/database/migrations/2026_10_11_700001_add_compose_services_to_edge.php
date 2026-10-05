<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Falak\Edge\Application\ComposeServiceDomains;

/**
 * Edge for every public service of a compose site (docs/plans/COMPOSE_APPS.md, phase 2): domains, redirects, basic
 * auth, headers and path mounts may target one compose service (`compose_service`; null = the site's primary service
 * for domains, every service for the rules), and IP lists per service. Existing public service domains (kept in the
 * site's `public_services` until now) become domain rows, and their Cloudflare records move to those rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_domains', function (Blueprint $table) {
            $table->string('compose_service', 63)->nullable()->after('site_id');
            $table->index(['site_id', 'compose_service']);
        });

        foreach (['edge_redirects', 'edge_security_rules'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('compose_service', 63)->nullable()->after('site_id'));
        }

        Schema::table('edge_headers', function (Blueprint $table) {
            $table->string('compose_service', 63)->nullable()->after('site_id');
            $table->dropUnique(['site_id', 'name']);
            $table->unique(['site_id', 'compose_service', 'name']);
        });

        Schema::table('edge_mounts', function (Blueprint $table) {
            $table->string('compose_service', 63)->nullable()->after('site_id');
            $table->dropUnique(['site_id', 'path_prefix']);
            $table->unique(['site_id', 'compose_service', 'path_prefix']);
        });

        Schema::create('edge_service_settings', function (Blueprint $table) {
            $table->ulid('site_id');
            $table->string('service', 63);
            $table->json('allow_ips');
            $table->json('deny_ips');
            $table->timestamps();
            $table->primary(['site_id', 'service']);
        });

        if (Schema::hasTable('sites_sites')) {
            app(ComposeServiceDomains::class)->importAll(DB::table('sites_sites')->where('runtime', 'compose')->pluck('id'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_service_settings');

        Schema::table('edge_mounts', function (Blueprint $table) {
            $table->dropUnique(['site_id', 'compose_service', 'path_prefix']);
            $table->unique(['site_id', 'path_prefix']);
            $table->dropColumn('compose_service');
        });

        Schema::table('edge_headers', function (Blueprint $table) {
            $table->dropUnique(['site_id', 'compose_service', 'name']);
            $table->unique(['site_id', 'name']);
            $table->dropColumn('compose_service');
        });

        foreach (['edge_redirects', 'edge_security_rules'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('compose_service'));
        }

        Schema::table('edge_domains', function (Blueprint $table) {
            $table->dropIndex(['site_id', 'compose_service']);
            $table->dropColumn('compose_service');
        });
    }
};
