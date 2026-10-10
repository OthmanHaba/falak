<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_control_webhooks', function (Blueprint $table) {
            // Kept while previews need the repository's pull request events, whatever push-to-deploy does.
            $table->boolean('pinned')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('source_control_webhooks', fn (Blueprint $table) => $table->dropColumn('pinned'));
    }
};
