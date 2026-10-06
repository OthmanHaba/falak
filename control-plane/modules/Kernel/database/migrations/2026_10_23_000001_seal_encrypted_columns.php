<?php

use Falak\Kernel\Security\Casts\Sealed;
use Falak\Kernel\Security\SealedColumns;
use Falak\Kernel\Security\Sealer;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;

/**
 * v0.10.0: the `encrypted` / `encrypted:array` casts (APP_KEY) became Sealed / SealedArray (data keys under
 * the KEK), and so did Fortify's two-factor columns (a Sealed cast on User). Re-encrypt every such column once:
 * Crypt::decryptString, then seal bound to the row: "<table>.<column>:<primary key>" (Sealed::aadFor).
 * Batched; values already sealed (fk1:) are skipped, so a migration interrupted halfway can run again.
 * The column list is frozen here on purpose (later models must not change what this migration touches).
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: string}> table, primary key, column */
    public const COLUMNS = [
        ['alerting_channels', 'id', 'config'],
        ['databases_storage_providers', 'id', 'access_key_id'],
        ['databases_storage_providers', 'id', 'secret_access_key'],
        ['databases_users', 'id', 'password'],
        ['deployments_deployments', 'id', 'variables'],
        ['deployments_releases', 'id', 'compose'],
        ['deployments_releases', 'id', 'environment'],
        ['deployments_site_settings', 'site_id', 'hook_token'],
        ['edge_certificates', 'id', 'key_pem'],
        ['edge_cloudflare_tunnels', 'id', 'token'],
        ['edge_dns_credentials', 'id', 'api_token'],
        ['fleet_certificate_authorities', 'id', 'private_key'],
        ['fleet_commands', 'id', 'payload'],
        // Fortify encrypted with serialize(); the serialized string is sealed as is (its encrypter only unserializes).
        ['identity_users', 'id', 'two_factor_recovery_codes'],
        ['identity_users', 'id', 'two_factor_secret'],
        ['network_private_network_members', 'id', 'private_key'],
        ['processes_daemons', 'id', 'env'],
        ['processes_workers', 'id', 'env'],
        ['providers_credentials', 'id', 'credentials'],
        ['recipes_runs', 'id', 'env'],
        ['servers_servers', 'id', 'install_command'],
        ['sites_compose_versions', 'id', 'content'],
        ['sites_environment_versions', 'id', 'variables'],
        ['source_control_connections', 'id', 'credentials'],
        ['source_control_deploy_keys', 'id', 'private_key'],
        ['source_control_github_apps', 'id', 'client_secret'],
        ['source_control_github_apps', 'id', 'private_key'],
        ['source_control_github_apps', 'id', 'webhook_secret'],
        ['source_control_webhooks', 'id', 'secret'],
        ['telemetry_settings', 'organization_id', 'otlp_token'],
    ];

    public function up(): void
    {
        $sealer = app(Sealer::class);

        foreach (self::COLUMNS as [$table, $primaryKey, $column]) {
            SealedColumns::rewrite($table, $primaryKey, $column, function (string $value, string $id) use ($sealer, $table, $column) {
                if (Sealer::isSealed($value)) {
                    return null;
                }

                try {
                    $plaintext = Crypt::decryptString($value);
                } catch (DecryptException $e) {
                    throw new RuntimeException("{$table}.{$column}: a value could not be decrypted with APP_KEY ({$e->getMessage()}). Was APP_KEY changed? Restore it and migrate again: values already converted are skipped.", previous: $e);
                }

                return $sealer->seal($plaintext, Sealed::aadFor($table, $column, $id));
            });
        }
    }

    public function down(): void
    {
        $sealer = app(Sealer::class);

        foreach (self::COLUMNS as [$table, $primaryKey, $column]) {
            SealedColumns::rewrite($table, $primaryKey, $column, fn (string $value, string $id) => Sealer::isSealed($value)
                ? Crypt::encryptString($sealer->open($value, Sealed::aadFor($table, $column, $id)))
                : null);
        }
    }
};
