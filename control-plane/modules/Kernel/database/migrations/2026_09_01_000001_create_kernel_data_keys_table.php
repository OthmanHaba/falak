<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Data keys, wrapped by the KEK (never stored unwrapped). purpose: 'platform' or 'org:<organization id>'.
        Schema::create('kernel_data_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('purpose', 64)->index();
            $table->text('wrapped_key');
            $table->string('kek_provider', 32);
            $table->string('kek_id', 255);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('retired_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kernel_data_keys');
    }
};
