<?php

namespace Falak\Secrets\Http\Controllers;

use Falak\Identity\Contracts\OrganizationDirectory;
use Falak\Secrets\Application\Scopes;
use Falak\Secrets\Contracts\AccessorType;
use Falak\Secrets\Domain\Models\AccessLogEntry;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Secrets\Domain\Models\SecretVersion;

/**
 * JSON shapes of secrets for the UI and the API. Metadata only: no value or reference ever goes through here.
 */
trait PresentsSecrets
{
    /** @var array<string, ?string> user id => name */
    private array $userNames = [];

    /** @var array<string, string> "scope:id" => label */
    private array $scopeLabels = [];

    /**
     * @param  list<array<string, mixed>>|null  $usedBy
     * @return array<string, mixed>
     */
    protected function presentSecret(Secret $secret, Scopes $scopes, ?array $usedBy = null): array
    {
        $labelKey = "{$secret->scope_type->value}:{$secret->scope_id}";

        return [
            'id' => $secret->id,
            'name' => $secret->name,
            'scope' => $secret->scope_type->value,
            'scope_id' => $secret->scope_id,
            'scope_label' => $this->scopeLabels[$labelKey] ??= $scopes->label($secret->scope_type, $secret->scope_id),
            'kind' => $secret->kind->value,
            'provider_id' => $secret->provider_id,
            'sensitive' => $secret->sensitive,
            'available_to_previews' => $secret->available_to_previews,
            'description' => $secret->description,
            'rotation_days' => $secret->rotation_days,
            'rotation_due_at' => $secret->rotationDueAt()?->toIso8601String(),
            'current_version' => $secret->current_version,
            'last_accessed_at' => $secret->last_accessed_at?->toIso8601String(),
            'created_at' => $secret->created_at->toIso8601String(),
            'updated_at' => $secret->updated_at->toIso8601String(),
            ...($usedBy !== null ? ['used_by' => $usedBy] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentVersion(Secret $secret, SecretVersion $version, OrganizationDirectory $directory): array
    {
        return [
            'version' => $version->version,
            'current' => $version->version === $secret->current_version,
            'restored_from' => $version->restored_from,
            'created_by' => $this->userName($version->created_by, $directory),
            'created_at' => $version->created_at->toIso8601String(),
            'disabled_at' => $version->disabled_at?->toIso8601String(),
        ];
    }

    /**
     * @param  bool  $detailed  IP addresses and actor ids (users with secrets.manage)
     * @return array<string, mixed>
     */
    protected function presentAccess(AccessLogEntry $entry, OrganizationDirectory $directory, bool $detailed): array
    {
        return [
            'id' => $entry->id,
            'version' => $entry->version,
            'actor_type' => $entry->actor_type->value,
            'actor' => match ($entry->actor_type) {
                AccessorType::User => $this->userName($entry->user_id, $directory) ?? 'Deleted user',
                AccessorType::ApiToken => 'API token'.(($name = $this->userName($entry->user_id, $directory)) !== null ? " of {$name}" : ''),
                AccessorType::Deployment => 'Deployment',
                AccessorType::Build => 'Build',
                AccessorType::System => 'Falak',
            },
            'actor_id' => $detailed ? $entry->actor_id : null,
            'reason' => $entry->reason,
            'ip' => $detailed ? $entry->ip : null,
            'created_at' => $entry->created_at->toIso8601String(),
        ];
    }

    private function userName(?string $userId, OrganizationDirectory $directory): ?string
    {
        if ($userId === null) {
            return null;
        }

        if (! array_key_exists($userId, $this->userNames)) {
            $this->userNames[$userId] = $directory->findUser($userId)?->name;
        }

        return $this->userNames[$userId];
    }
}
