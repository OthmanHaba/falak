<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Organization templates (docs/COMPOSE_TEMPLATES.md §2): same format as the catalog, versioned.
        Schema::create('templates_custom', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('slug', 50);
            $table->string('name', 60);
            $table->string('version', 32);
            $table->string('category', 32);
            $table->string('description', 300);
            $table->text('template_yaml');
            $table->text('compose_yaml');
            $table->unsignedInteger('revision')->default(1);
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
        });

        Schema::create('templates_custom_revisions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('template_id')->index();
            $table->unsignedInteger('revision');
            $table->string('version', 32);
            $table->text('template_yaml');
            $table->text('compose_yaml');
            $table->ulid('created_by')->nullable();
            $table->timestamp('created_at');

            $table->unique(['template_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates_custom_revisions');
        Schema::dropIfExists('templates_custom');
    }
};
