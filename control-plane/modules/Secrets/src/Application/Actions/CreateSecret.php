<?php

namespace Falak\Secrets\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Secrets\Application\Providers\LinkedReferences;
use Falak\Secrets\Application\Scopes;
use Falak\Secrets\Application\SecretCipher;
use Falak\Secrets\Contracts\SecretScope;
use Falak\Secrets\Domain\Enums\OnChange;
use Falak\Secrets\Domain\Enums\SecretKind;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Secrets\Domain\Models\SecretVersion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A new secret with its first version. The audit log gets the name and scope, never the value.
 */
final class CreateSecret
{
    public function __construct(
        private readonly Scopes $scopes,
        private readonly SecretCipher $cipher,
        private readonly LinkedReferences $references,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array{name: string, kind?: string, value?: ?string, reference?: ?string, provider_id?: ?string, watch_minutes?: ?int, on_change?: ?string, sensitive?: bool, available_to_previews?: bool, description?: ?string, rotation_days?: ?int}  $data
     */
    public function __invoke(string $organizationId, SecretScope $scope, string $scopeId, #[\SensitiveParameter] array $data, ?string $userId): Secret
    {
        $scopeId = strtolower($scopeId);

        if (! $this->scopes->belongsTo($organizationId, $scope, $scopeId)) {
            throw ValidationException::withMessages(['scope_id' => 'Choose a project, environment or service of this organization.']);
        }

        $kind = SecretKind::from($data['kind'] ?? SecretKind::Managed->value);
        $plaintext = $kind === SecretKind::Linked ? trim((string) ($data['reference'] ?? '')) : (string) ($data['value'] ?? '');

        if ($kind === SecretKind::Linked && $plaintext === '') {
            throw ValidationException::withMessages(['reference' => 'Enter the reference of the value at the provider.']);
        }

        $providerId = $kind === SecretKind::Linked ? $this->references->validate($organizationId, $data['provider_id'] ?? null, $plaintext)->id : null;
        $watch = $kind === SecretKind::Linked ? ($data['watch_minutes'] ?? null) : null;

        try {
            return $this->create($organizationId, $scope, $scopeId, $data, $userId, $kind, $plaintext, $providerId, $watch);
        } catch (UniqueConstraintViolationException) {
            // Two creations of the same name at once: the second loses on the unique index.
            throw ValidationException::withMessages(['name' => "A secret named {$data['name']} already exists here."]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function create(string $organizationId, SecretScope $scope, string $scopeId, #[\SensitiveParameter] array $data, ?string $userId, SecretKind $kind, #[\SensitiveParameter] string $plaintext, ?string $providerId, ?int $watch): Secret
    {
        return DB::transaction(function () use ($organizationId, $scope, $scopeId, $data, $userId, $kind, $plaintext, $providerId, $watch) {
            if (Secret::query()->where('scope_type', $scope->value)->where('scope_id', $scopeId)->where('name', $data['name'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['name' => "A secret named {$data['name']} already exists here."]);
            }

            $secret = Secret::query()->create([
                'organization_id' => $organizationId,
                'scope_type' => $scope,
                'scope_id' => $scopeId,
                'name' => $data['name'],
                'kind' => $kind,
                'provider_id' => $providerId,
                'watch_minutes' => $watch,
                'on_change' => $kind === SecretKind::Linked ? OnChange::from($data['on_change'] ?? OnChange::None->value) : OnChange::None,
                'next_poll_at' => $watch !== null ? now() : null,
                'sensitive' => (bool) ($data['sensitive'] ?? true),
                'available_to_previews' => (bool) ($data['available_to_previews'] ?? false),
                'description' => $data['description'] ?? null,
                'rotation_days' => $data['rotation_days'] ?? null,
                'current_version' => 1,
                'created_by' => $userId,
            ]);

            SecretVersion::query()->create([
                'secret_id' => $secret->id,
                'version' => 1,
                'ciphertext' => $this->cipher->seal($secret, 1, $plaintext),
                'created_by' => $userId,
                'created_at' => now(),
            ]);

            $this->audit->record('secret.created', 'secret', $secret->id, [
                'name' => $secret->name,
                'scope' => $scope->value,
                'scope_id' => $scopeId,
                'kind' => $kind->value,
                'sensitive' => $secret->sensitive,
            ], $organizationId);

            return $secret;
        });
    }
}
