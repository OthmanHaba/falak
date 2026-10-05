<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Settings → Domains: which service generated names use (null = the server default, FALAK_GENERATED_DOMAIN_SUFFIX).
        Schema::create('edge_organization_settings', function (Blueprint $table) {
            $table->ulid('organization_id')->primary();
            $table->string('generated_domain_provider', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_organization_settings');
    }
};
