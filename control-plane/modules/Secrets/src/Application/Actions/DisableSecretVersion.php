<?php

namespace Falak\Secrets\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Secrets\Domain\Models\SecretVersion;
use Illuminate\Validation\ValidationException;

/**
 * Disable an old version (e.g. a leaked value) so no one can roll back to it or reveal it. The current version
 * can't be disabled: set a new value or roll back first.
 */
final class DisableSecretVersion
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(Secret $secret, int $version): SecretVersion
    {
        $target = SecretVersion::query()->where('secret_id', $secret->id)->where('version', $version)->first();

        if ($target === null) {
            throw ValidationException::withMessages(['version' => "Version {$version} does not exist."]);
        }

        if ($version === $secret->current_version) {
            throw ValidationException::withMessages(['version' => 'The current version is in use; set a new value or roll back before disabling it.']);
        }

        if ($target->disabled_at === null) {
            $target->forceFill(['disabled_at' => now()])->save();
            $this->audit->record('secret.version_disabled', 'secret', $secret->id, ['name' => $secret->name, 'version' => $version], $secret->organization_id);
        }

        return $target;
    }
}
