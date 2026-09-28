<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // GitHub Apps registered through the manifest flow: one per Kiln organization (docs/INTEGRATION-NOTES.md).
        Schema::create('source_control_github_apps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->unique();
            $table->string('app_id', 32);
            $table->string('slug');
            $table->string('name');
            $table->string('owner_login')->nullable();
            $table->string('owner_type', 32)->nullable();
            $table->string('html_url', 500)->nullable();
            $table->string('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->text('webhook_secret');
            $table->text('private_key');
            $table->ulid('created_by')->nullable();
            $table->timestamp('last_delivery_at')->nullable();
            $table->timestamps();
        });

        Schema::table('source_control_connections', function (Blueprint $table) {
            $table->string('status', 16)->default('active');
            // GitHub App connections: which app ("env" or a source_control_github_apps id) and installation.
            $table->string('github_app_id', 26)->nullable();
            $table->string('installation_id', 32)->nullable()->index();
        });

        // Existing app connections were created with the env-configured app; lift their installation id out of the
        // encrypted credentials so webhooks can find them.
        DB::table('source_control_connections')->where('auth_type', 'app')->orderBy('id')->each(function (object $row) {
            try {
                $credentials = json_decode(Crypt::decryptString((string) $row->credentials), true);
            } catch (Throwable) {
                return;
            }

            DB::table('source_control_connections')->where('id', $row->id)->update([
                'github_app_id' => 'env',
                'installation_id' => is_array($credentials) && isset($credentials['installation_id']) ? (string) $credentials['installation_id'] : null,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('source_control_connections', function (Blueprint $table) {
            $table->dropIndex(['installation_id']);
            $table->dropColumn(['status', 'github_app_id', 'installation_id']);
        });

        Schema::dropIfExists('source_control_github_apps');
    }
};
