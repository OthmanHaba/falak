<?php

use Spatie\Permission\DefaultTeamResolver;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * spatie/laravel-permission, owned by the Identity module.
 *
 * Organizations are the permission "team": every role assignment is scoped to an
 * organization (identity_model_has_roles.organization_id). Roles themselves are global
 * (organization_id = null) and synced from the Identity PermissionRegistry after migrations.
 *
 * The package's Gate::before hook is disabled on purpose: authorization always goes through
 * Kiln\Identity\Contracts\OrganizationAccess so every check is explicitly organization-scoped.
 */
return [
    'models' => [
        'permission' => Permission::class,
        'role' => Role::class,
        'team' => null,
        'default_model' => null,
    ],

    'table_names' => [
        'roles' => 'identity_roles',
        'permissions' => 'identity_permissions',
        'model_has_permissions' => 'identity_model_has_permissions',
        'model_has_roles' => 'identity_model_has_roles',
        'role_has_permissions' => 'identity_role_has_permissions',
    ],

    'column_names' => [
        'role_pivot_key' => null,
        'permission_pivot_key' => null,
        'model_morph_key' => 'model_id',
        'team_foreign_key' => 'organization_id',
    ],

    'register_permission_check_method' => false,
    'register_octane_reset_listener' => true,
    'events_enabled' => false,
    'teams' => true,
    'team_resolver' => DefaultTeamResolver::class,
    'use_passport_client_credentials' => false,
    'display_permission_in_exception' => false,
    'display_role_in_exception' => false,
    'enable_wildcard_permission' => false,

    'cache' => [
        'expiration_time' => DateInterval::createFromDateString('24 hours'),
        'key' => 'kiln.identity.permission.cache',
        'store' => 'default',
    ],
];
