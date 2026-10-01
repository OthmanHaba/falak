<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who may call a function (enforced by the gateway, docs/FUNCTIONS.md): API keys (stored hashed, shown once)
        // and an IP allowlist.
        Schema::create('functions_api_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('function_id')->index();
            $table->string('name', 64);
            $table->char('hash', 64)->unique();
            $table->string('prefix', 16);
            $table->ulid('created_by')->nullable();
            $table->timestamps();
        });

        Schema::table('functions_functions', function (Blueprint $table) {
            $table->json('allow_cidrs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('functions_functions', fn (Blueprint $table) => $table->dropColumn('allow_cidrs'));
        Schema::dropIfExists('functions_api_keys');
    }
};
