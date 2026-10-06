<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // External secret providers of an organization (linked secrets resolve through them).
        Schema::create('secrets_providers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('name', 100);
            // vault | aws_secrets_manager | aws_ssm | onepassword | doppler | infisical | http
            $table->string('type', 32);
            // Sealed JSON: endpoint and credentials (never returned to the browser).
            $table->text('config');
            // Bumped whenever the config changes: cached values and logins are bound to it.
            $table->unsignedInteger('config_version')->default(1);
            // Self-hosted providers on a private network (never link-local / cloud metadata addresses).
            $table->boolean('allow_private_network')->default(false);
            $table->unsignedInteger('cache_ttl_seconds')->default(300);
            $table->string('status', 16)->default('untested');
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        // The last good value of each reference, sealed under the organization's data key: fresh within the
        // provider's TTL, and the fallback when the provider is unreachable.
        Schema::create('secrets_provider_values', function (Blueprint $table) {
            $table->id();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('provider_id')->constrained('secrets_providers')->cascadeOnDelete();
            $table->char('reference_hash', 64);
            $table->text('ciphertext');
            $table->timestamp('fetched_at');
            $table->unique(['provider_id', 'reference_hash']);
        });

        Schema::table('secrets_secrets', function (Blueprint $table) {
            // Watch (linked secrets): poll every N minutes; on a change, a new version and this action.
            $table->unsignedSmallInteger('watch_minutes')->nullable();
            $table->string('on_change', 16)->default('none');
            $table->timestamp('next_poll_at')->nullable()->index();
            $table->timestamp('last_polled_at')->nullable();
            // "<data key id>:<HMAC-SHA256>" of the last value seen upstream (keyed from the organization's data key).
            $table->string('value_hmac', 128)->nullable();
        });

        Schema::table('secrets_versions', function (Blueprint $table) {
            // Linked secrets: the value resolved when the version was made (sealed like the reference, own AAD).
            // A rollback to such a version pins the secret to it.
            $table->text('snapshot')->nullable();
            $table->string('note', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('secrets_versions', function (Blueprint $table) {
            $table->dropColumn(['snapshot', 'note']);
        });

        Schema::table('secrets_secrets', function (Blueprint $table) {
            $table->dropIndex(['next_poll_at']);
            $table->dropColumn(['watch_minutes', 'on_change', 'next_poll_at', 'last_polled_at', 'value_hmac']);
        });

        Schema::dropIfExists('secrets_provider_values');
        Schema::dropIfExists('secrets_providers');
    }
};
