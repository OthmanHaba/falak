<?php

namespace Falak\Secrets\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Secrets\Application\Providers\ProviderFields;
use Falak\Secrets\Domain\Enums\ProviderStatus;
use Falak\Secrets\Domain\Enums\ProviderType;
use Falak\Secrets\Domain\Models\SecretProvider;
use Falak\Secrets\Infrastructure\Providers\EndpointGuard;
use Falak\Secrets\Infrastructure\Providers\ProviderFailure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create a provider, or change one (its type is fixed). Credentials left empty on edit keep their stored value;
 * fields that don't apply (another auth method) are dropped. Endpoints are checked against the endpoint guard.
 * The audit log gets names (of the provider and of the settings changed), never a value.
 */
final class SaveSecretProvider
{
    public function __construct(
        private readonly EndpointGuard $guard,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array{name?: string, type?: string, config?: array<string, mixed>, allow_private_network?: bool, cache_ttl_seconds?: int}  $data
     */
    public function __invoke(string $organizationId, ?SecretProvider $provider, #[\SensitiveParameter] array $data, ?string $userId): SecretProvider
    {
        $type = $provider->type ?? ProviderType::from((string) $data['type']);
        $allowPrivate = (bool) ($data['allow_private_network'] ?? $provider?->allow_private_network ?? false) && $type->selfHostable();

        if ($allowPrivate && ! config('secrets.providers.allow_private_network', true)) {
            throw ValidationException::withMessages(['allow_private_network' => 'This Falak instance does not allow providers on private networks.']);
        }

        $config = $this->config($type, (array) ($data['config'] ?? []), $provider?->config ?? [], $allowPrivate);
        $name = trim((string) ($data['name'] ?? $provider?->name));

        return DB::transaction(function () use ($organizationId, $provider, $data, $userId, $type, $allowPrivate, $config, $name) {
            if (SecretProvider::query()->where('organization_id', $organizationId)->where('name', $name)->when($provider, fn ($q) => $q->whereKeyNot($provider->id))->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['name' => "A provider named {$name} already exists."]);
            }

            $changedSettings = $provider === null ? [] : array_keys(array_filter(
                array_flip(array_unique([...array_keys($config), ...array_keys($provider->config)])),
                fn ($_, string $key) => ($config[$key] ?? null) !== ($provider->config[$key] ?? null),
                ARRAY_FILTER_USE_BOTH,
            ));

            $model = $provider ?? new SecretProvider(['organization_id' => $organizationId, 'type' => $type, 'created_by' => $userId]);
            $model->fill([
                'name' => $name,
                'allow_private_network' => $allowPrivate,
                'cache_ttl_seconds' => (int) ($data['cache_ttl_seconds'] ?? $provider?->cache_ttl_seconds ?? 300),
            ]);

            if ($provider === null || $changedSettings !== [] || $model->isDirty('allow_private_network')) {
                $model->config = $config;
                $model->status = ProviderStatus::Untested;
                $model->last_error = null;
            }

            $dirty = array_keys($model->getDirty());
            $model->save();

            if ($provider === null) {
                $this->audit->record('secret_provider.created', 'secret_provider', $model->id, ['name' => $name, 'type' => $type->value], $organizationId);
            } elseif ($dirty !== []) {
                $this->audit->record('secret_provider.updated', 'secret_provider', $model->id, [
                    'name' => $name,
                    'changed' => array_values(array_diff($dirty, ['config', 'status', 'last_error'])),
                    'settings' => $changedSettings,
                ], $organizationId);
            }

            return $model;
        });
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @param  array<string, mixed>  $stored
     * @return array<string, string>
     */
    private function config(ProviderType $type, #[\SensitiveParameter] array $incoming, #[\SensitiveParameter] array $stored, bool $allowPrivate): array
    {
        $fields = ProviderFields::for($type);
        $merged = [];

        // Two passes: `when` depends on the select values, which come first.
        foreach ($fields as $field) {
            $value = $incoming[$field['name']] ?? null;
            $value = is_scalar($value) ? trim((string) $value) : '';

            if ($value === '' && ($field['secret'] ?? false)) {
                $value = (string) ($stored[$field['name']] ?? '');
            }

            $merged[$field['name']] = $value !== '' ? $value : (string) ($field['default'] ?? '');
        }

        $config = [];
        $errors = [];

        foreach ($fields as $field) {
            $name = $field['name'];
            $value = $merged[$name];

            if (! ProviderFields::applies($field, $merged, $type) || $value === '') {
                if ($field['required'] && ProviderFields::applies($field, $merged, $type)) {
                    $errors["config.{$name}"] = "Enter the {$field['label']}.";
                }

                continue;
            }

            $error = match (true) {
                strlen($value) > ($field['kind'] === 'textarea' ? 20000 : 8192) => 'This is too long.',
                isset($field['options']) && ! in_array($value, array_column($field['options'], 'value'), true) => 'Choose one of the options.',
                isset($field['pattern']) && preg_match($field['pattern'], $value) !== 1 => "This is not a valid {$field['label']}.",
                $field['kind'] === 'textarea' && ! str_contains($value, '-----BEGIN CERTIFICATE-----') => 'Paste a PEM certificate (-----BEGIN CERTIFICATE-----).',
                $field['kind'] === 'url' => $this->urlError($value, $allowPrivate),
                default => null,
            };

            if ($error !== null) {
                $errors["config.{$name}"] = $error;

                continue;
            }

            $config[$name] = $field['kind'] === 'url' ? rtrim($value, '/') : $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $config;
    }

    private function urlError(string $url, bool $allowPrivate): ?string
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            return 'Enter an https:// URL (no credentials, query or fragment).';
        }

        try {
            $this->guard->check($url, $allowPrivate);
        } catch (ProviderFailure $e) {
            return $e->getMessage();
        }

        return null;
    }
}
