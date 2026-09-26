<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_organizations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignUlid('owner_id')->constrained('identity_users')->restrictOnDelete();
            $table->boolean('personal')->default(false);
            $table->timestamps();
        });

        Schema::create('identity_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->constrained('identity_organizations')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('identity_users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
        });

        Schema::create('identity_teams', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('identity_organizations')->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('identity_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('team_id')->constrained('identity_teams')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('identity_users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['team_id', 'user_id']);
        });

        Schema::create('identity_invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('identity_organizations')->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 32);
            $table->string('token_hash', 64)->unique();
            $table->foreignUlid('invited_by')->nullable()->constrained('identity_users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_invitations');
        Schema::dropIfExists('identity_team_members');
        Schema::dropIfExists('identity_teams');
        Schema::dropIfExists('identity_memberships');
        Schema::dropIfExists('identity_organizations');
    }
};
