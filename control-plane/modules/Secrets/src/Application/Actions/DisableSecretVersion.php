<?php

namespace Falak\Secrets\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Secrets\Domain\Models\SecretVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Disable an old version (e.g. a leaked value) so no one can roll back to it or reveal it, together with every
 * rollback copy of it (versions restored from it, transitively). Refused while the current version is the value
 * or a copy of it: set a new value first.
 */
final class DisableSecretVersion
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @return list<int> the versions disabled by this call
     */
    public function __invoke(Secret $secret, int $version): array
    {
        return DB::transaction(function () use ($secret, $version) {
            // Serializes with SetSecretValue / RollBackSecret, which lock the same row.
            $locked = Secret::query()->whereKey($secret->id)->lockForUpdate()->firstOrFail();
            $versions = SecretVersion::query()->where('secret_id', $locked->id)->orderBy('version')->get()->keyBy('version');

            if (! $versions->has($version)) {
                throw ValidationException::withMessages(['version' => "Version {$version} does not exist."]);
            }

            // N and the versions restored from N or from one of its copies.
            $lineage = [$version => true];

            foreach ($versions as $candidate) {
                if ($candidate->restored_from !== null && isset($lineage[$candidate->restored_from])) {
                    $lineage[$candidate->version] = true;
                }
            }

            if (isset($lineage[$locked->current_version])) {
                throw ValidationException::withMessages(['version' => $locked->current_version === $version
                    ? 'The current version is in use; set a new value or roll back before disabling it.'
                    : "The current version (v{$locked->current_version}) is a rollback copy of v{$version}; set a new value before disabling it."]);
            }

            $disabled = [];

            foreach (array_keys($lineage) as $number) {
                $target = $versions[$number];

                if ($target->disabled_at === null) {
                    $target->forceFill(['disabled_at' => now()])->save();
                    $disabled[] = (int) $number;
                }
            }

            if ($disabled !== []) {
                $this->audit->record('secret.version_disabled', 'secret', $locked->id, ['name' => $locked->name, 'version' => $version, 'disabled' => $disabled], $locked->organization_id);
            }

            $secret->setRawAttributes($locked->getAttributes(), true);

            return $disabled;
        });
    }
}
