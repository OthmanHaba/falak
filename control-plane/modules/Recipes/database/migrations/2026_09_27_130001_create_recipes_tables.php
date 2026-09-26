<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes_recipes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->text('script');
            $table->string('user', 32)->default('root');
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('recipes_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('recipe_id')->nullable()->index();
            $table->string('builtin', 64)->nullable();
            $table->string('recipe_name', 100);
            $table->text('script');
            $table->string('user', 32);
            $table->text('env')->nullable();
            $table->unsignedInteger('timeout_s');
            $table->ulid('requested_by')->nullable();
            $table->string('status', 16)->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('recipes_run_targets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('run_id')->index();
            $table->ulid('organization_id');
            $table->ulid('server_id')->index();
            $table->string('server_name');
            $table->ulid('command_id')->nullable()->unique();
            $table->string('status', 16);
            $table->integer('exit_code')->nullable();
            $table->string('error', 1000)->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipes_run_targets');
        Schema::dropIfExists('recipes_runs');
        Schema::dropIfExists('recipes_recipes');
    }
};
