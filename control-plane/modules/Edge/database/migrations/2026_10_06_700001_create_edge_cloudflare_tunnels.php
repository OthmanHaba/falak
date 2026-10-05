<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cloudflare Tunnel per server (ingress mode "tunnel"): Falak creates the tunnel with a Cloudflare connection, the
 * agent runs cloudflared with its token, and names served by the server point at <tunnel id>.cfargotunnel.com.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_cloudflare_tunnels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('server_id')->unique();
            $table->foreignUlid('dns_credential_id')->constrained('edge_dns_credentials')->cascadeOnDelete();
            $table->string('account_id', 64);
            $table->string('tunnel_id', 64);
            $table->string('name');
            $table->text('token');
            $table->string('status', 16)->default('installing'); // installing | active | error
            $table->text('error')->nullable();
            $table->ulid('command_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_cloudflare_tunnels');
    }
};
