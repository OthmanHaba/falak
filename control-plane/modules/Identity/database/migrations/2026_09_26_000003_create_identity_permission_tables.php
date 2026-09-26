<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spatie/laravel-permission tables with organization-scoped ("teams") assignments and ULID models.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('identity_roles', function (Blueprint $table) {
            $table->id();
            $table->ulid('organization_id')->nullable()->index('identity_roles_organization_index');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['organization_id', 'name', 'guard_name']);
        });

        Schema::create('identity_model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->ulid('model_id');
            $table->ulid('organization_id');
            $table->index(['model_id', 'model_type'], 'identity_mhp_model_index');
            $table->index('organization_id', 'identity_mhp_organization_index');
            $table->foreign('permission_id')->references('id')->on('identity_permissions')->cascadeOnDelete();
            $table->primary(['organization_id', 'permission_id', 'model_id', 'model_type'], 'identity_mhp_primary');
        });

        Schema::create('identity_model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->ulid('model_id');
            $table->ulid('organization_id');
            $table->index(['model_id', 'model_type'], 'identity_mhr_model_index');
            $table->index('organization_id', 'identity_mhr_organization_index');
            $table->foreign('role_id')->references('id')->on('identity_roles')->cascadeOnDelete();
            $table->primary(['organization_id', 'role_id', 'model_id', 'model_type'], 'identity_mhr_primary');
        });

        Schema::create('identity_role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->foreign('permission_id')->references('id')->on('identity_permissions')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('identity_roles')->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id'], 'identity_rhp_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_role_has_permissions');
        Schema::dropIfExists('identity_model_has_roles');
        Schema::dropIfExists('identity_model_has_permissions');
        Schema::dropIfExists('identity_roles');
        Schema::dropIfExists('identity_permissions');
    }
};
