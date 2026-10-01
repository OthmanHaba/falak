<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A function's schedules: each runs its scheduled() handler in a one-shot container (docs/FUNCTIONS.md).
        Schema::create('functions_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('function_id')->index();
            $table->string('name', 64);
            $table->string('expression', 64);
            $table->string('timezone', 64)->default('UTC');
            $table->string('overlap', 8)->default('skip');
            $table->unsignedInteger('timeout_s')->default(300);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('functions_schedules');
    }
};
