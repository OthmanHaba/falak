<?php

namespace Falak\Secrets\Application\Providers;

use Falak\Secrets\Domain\Enums\ProviderType;
use Falak\Secrets\Infrastructure\Providers\Drivers\AwsDriver;
use Falak\Secrets\Infrastructure\Providers\Drivers\InfisicalDriver;

/**
 * The settings of each provider type: what the add / edit dialog shows, what is validated, and which fields are
 * credentials (write-only: sealed, never sent back, kept when left empty on edit).
 *
 * Field kinds: text, url (https only, checked against the endpoint guard), secret, textarea (PEM), select.
 * `when` shows (and requires) a field only for some values of another.
 */
final class ProviderFields
{
    /**
     * `binds`: decides where the credentials are sent (changing it requires entering them again); `path`: slash
     * separated, without empty, "." or ".." segments.
     *
     * @return list<array{name: string, label: string, kind: string, required: bool, secret?: bool, binds?: bool, path?: bool, options?: list<array{value: string, label: string}>, default?: string, placeholder?: string, hint?: string, when?: array{field: string, in: list<string>}, pattern?: string}>
     */
    public static function for(ProviderType $type): array
    {
        $ca = ['name' => 'ca_pem', 'label' => 'CA certificate (PEM)', 'kind' => 'textarea', 'required' => false, 'hint' => 'Only for a private CA. TLS is always verified.', 'placeholder' => '-----BEGIN CERTIFICATE-----', 'binds' => true];

        return match ($type) {
            ProviderType::Vault => [
                ['name' => 'address', 'label' => 'Address', 'kind' => 'url', 'required' => true, 'binds' => true, 'placeholder' => 'https://vault.example.com:8200'],
                ['name' => 'namespace', 'label' => 'Namespace', 'kind' => 'text', 'required' => false, 'binds' => true, 'path' => true, 'hint' => 'Vault Enterprise / HCP only.', 'pattern' => '/^[A-Za-z0-9_\/-]{1,200}$/'],
                ['name' => 'kv_version', 'label' => 'KV engine', 'kind' => 'select', 'required' => true, 'default' => '2', 'options' => [['value' => '2', 'label' => 'KV v2 (vault://<mount>/data/<path>#key)'], ['value' => '1', 'label' => 'KV v1 (vault://<mount>/<path>#key)']]],
                ['name' => 'auth_method', 'label' => 'Authentication', 'kind' => 'select', 'required' => true, 'default' => 'approle', 'options' => [['value' => 'approle', 'label' => 'AppRole'], ['value' => 'token', 'label' => 'Token'], ['value' => 'jwt', 'label' => 'JWT / OIDC role']]],
                ['name' => 'token', 'label' => 'Token', 'kind' => 'secret', 'required' => true, 'secret' => true, 'when' => ['field' => 'auth_method', 'in' => ['token']]],
                ['name' => 'role_id', 'label' => 'Role ID', 'kind' => 'text', 'required' => true, 'when' => ['field' => 'auth_method', 'in' => ['approle']], 'pattern' => '/^[A-Za-z0-9_.:-]{1,200}$/'],
                ['name' => 'secret_id', 'label' => 'Secret ID', 'kind' => 'secret', 'required' => true, 'secret' => true, 'when' => ['field' => 'auth_method', 'in' => ['approle']]],
                ['name' => 'role', 'label' => 'Role', 'kind' => 'text', 'required' => true, 'when' => ['field' => 'auth_method', 'in' => ['jwt']], 'pattern' => '/^[A-Za-z0-9_.:-]{1,200}$/'],
                ['name' => 'jwt', 'label' => 'JWT', 'kind' => 'secret', 'required' => true, 'secret' => true, 'when' => ['field' => 'auth_method', 'in' => ['jwt']]],
                ['name' => 'auth_mount', 'label' => 'Auth mount', 'kind' => 'text', 'required' => false, 'path' => true, 'hint' => 'Defaults to approle or jwt.', 'when' => ['field' => 'auth_method', 'in' => ['approle', 'jwt']], 'pattern' => '/^[A-Za-z0-9_\/-]{1,200}$/'],
                $ca,
            ],
            ProviderType::AwsSecretsManager, ProviderType::AwsSsm => [
                ['name' => 'region', 'label' => 'Region', 'kind' => 'text', 'required' => true, 'binds' => true, 'placeholder' => 'eu-central-1', 'pattern' => AwsDriver::REGION_PATTERN],
                ['name' => 'auth_method', 'label' => 'Credentials', 'kind' => 'select', 'required' => true, 'default' => 'keys', 'options' => array_values(array_filter([
                    ['value' => 'keys', 'label' => 'Access key'],
                    config('secrets.providers.allow_instance_profile', false) ? ['value' => 'instance_profile', 'label' => 'Control plane instance profile'] : null,
                ]))],
                ['name' => 'access_key_id', 'label' => 'Access key ID', 'kind' => 'text', 'required' => true, 'when' => ['field' => 'auth_method', 'in' => ['keys']], 'pattern' => '/^[A-Z0-9]{16,128}$/'],
                ['name' => 'secret_access_key', 'label' => 'Secret access key', 'kind' => 'secret', 'required' => true, 'secret' => true, 'when' => ['field' => 'auth_method', 'in' => ['keys']]],
                ['name' => 'session_token', 'label' => 'Session token', 'kind' => 'secret', 'required' => false, 'secret' => true, 'when' => ['field' => 'auth_method', 'in' => ['keys']], 'hint' => 'Only for temporary credentials.'],
                ['name' => 'role_arn', 'label' => 'Assume role ARN', 'kind' => 'text', 'required' => false, 'binds' => true, 'hint' => 'STS AssumeRole with the credentials above (required with the instance profile).', 'placeholder' => 'arn:aws:iam::123456789012:role/falak-secrets', 'pattern' => '/^arn:aws[a-z-]*:iam::\d{12}:role\/[\w+=,.@\/-]{1,512}$/'],
                ['name' => 'external_id', 'label' => 'External ID', 'kind' => 'text', 'required' => false, 'binds' => true, 'when' => ['field' => 'auth_method', 'in' => ['keys']], 'pattern' => '/^[\w+=,.@:\/-]{2,1224}$/'],
            ],
            ProviderType::OnePassword => [
                ['name' => 'connect_url', 'label' => 'Connect server URL', 'kind' => 'url', 'required' => true, 'binds' => true, 'placeholder' => 'https://op-connect.example.com'],
                ['name' => 'token', 'label' => 'Connect token', 'kind' => 'secret', 'required' => true, 'secret' => true],
                $ca,
            ],
            ProviderType::Doppler => [
                ['name' => 'token', 'label' => 'Service token', 'kind' => 'secret', 'required' => true, 'secret' => true, 'placeholder' => 'dp.st.…'],
            ],
            ProviderType::Infisical => [
                ['name' => 'base_url', 'label' => 'URL', 'kind' => 'url', 'required' => true, 'binds' => true, 'default' => InfisicalDriver::CLOUD, 'hint' => 'Your own URL when self-hosted.'],
                ['name' => 'client_id', 'label' => 'Client ID', 'kind' => 'text', 'required' => true, 'pattern' => '/^[A-Za-z0-9_.-]{1,200}$/'],
                ['name' => 'client_secret', 'label' => 'Client secret', 'kind' => 'secret', 'required' => true, 'secret' => true],
                $ca,
            ],
            ProviderType::Http => [
                ['name' => 'base_url', 'label' => 'Base URL', 'kind' => 'url', 'required' => true, 'binds' => true, 'placeholder' => 'https://secrets.example.com/v1', 'hint' => 'Falak calls GET {base URL}?ref=<name> and expects {"value": "…"}.'],
                ['name' => 'header_name', 'label' => 'Auth header', 'kind' => 'text', 'required' => false, 'binds' => true, 'default' => 'Authorization', 'pattern' => '/^[A-Za-z0-9-]{1,100}$/'],
                ['name' => 'header_value', 'label' => 'Auth header value', 'kind' => 'secret', 'required' => false, 'secret' => true, 'placeholder' => 'Bearer …'],
                $ca,
            ],
        };
    }

    /**
     * @return list<string>
     */
    public static function secretNames(ProviderType $type): array
    {
        return array_values(array_map(fn (array $field) => $field['name'], array_filter(self::for($type), fn (array $field) => ($field['secret'] ?? false) === true)));
    }

    /**
     * Whether a field applies given the other settings (its `when`).
     *
     * @param  array{when?: array{field: string, in: list<string>}}  $field
     * @param  array<string, mixed>  $config
     */
    public static function applies(array $field, array $config, ProviderType $type): bool
    {
        if (! isset($field['when'])) {
            return true;
        }

        $other = collect(self::for($type))->firstWhere('name', $field['when']['field']);
        $value = (string) ($config[$field['when']['field']] ?? $other['default'] ?? '');

        return in_array($value, $field['when']['in'], true);
    }
}
