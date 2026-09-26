<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('providers_credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('provider', 32);
            $table->string('name', 100);
            $table->text('credentials');
            $table->string('status', 16)->default('active');
            $table->timestamp('last_verified_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('providers_credentials');
    }
};
