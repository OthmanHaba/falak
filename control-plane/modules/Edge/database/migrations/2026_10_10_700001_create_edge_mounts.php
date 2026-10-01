<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A path of a site (its domains) served by a function: app.example.com/api/* → the function.
        Schema::create('edge_mounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('site_id');
            $table->ulid('function_site_id')->index();
            $table->string('path_prefix', 200);
            $table->boolean('strip_prefix')->default(false);
            $table->timestamps();

            $table->unique(['site_id', 'path_prefix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_mounts');
    }
};
