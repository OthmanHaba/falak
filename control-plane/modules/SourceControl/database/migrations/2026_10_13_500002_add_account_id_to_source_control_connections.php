<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_control_connections', function (Blueprint $table) {
            // The provider's immutable id of the account the connection authenticates as (GitHub / GitLab user id,
            // Bitbucket account id): logins and nicknames can be renamed and reused, ids can't.
            $table->string('account_id', 100)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('source_control_connections', function (Blueprint $table) {
            $table->dropIndex(['account_id']);
            $table->dropColumn('account_id');
        });
    }
};
