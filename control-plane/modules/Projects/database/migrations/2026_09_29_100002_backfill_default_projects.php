<?php

use Falak\Projects\Application\Actions\BackfillProjects;
use Illuminate\Database\Migrations\Migration;

/**
 * Data migration: every organization gets a "Default" project with a production environment and every
 * existing site / database is placed in it. Idempotent (also available as `projects:backfill`).
 */
return new class extends Migration
{
    public function up(): void
    {
        app(BackfillProjects::class)();
    }

    public function down(): void
    {
        // Structural rollback drops the tables.
    }
};
