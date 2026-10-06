<?php

namespace Falak\Secrets\Application\Actions;

use Falak\Secrets\Application\SecretCipher;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Secrets\Domain\Models\SecretVersion;
use Illuminate\Validation\ValidationException;

/**
 * Roll back to version N: a new version with N's value (re-sealed for its own version number). History is
 * never rewritten. The copy happens on the server, so it is not a read of the value (no access log entry).
 */
final class RollBackSecret
{
    public function __construct(
        private readonly SecretCipher $cipher,
        private readonly SetSecretValue $set,
    ) {}

    public function __invoke(Secret $secret, int $version, ?string $userId): SecretVersion
    {
        $target = SecretVersion::query()->where('secret_id', $secret->id)->where('version', $version)->first();

        if ($target === null) {
            throw ValidationException::withMessages(['version' => "Version {$version} does not exist."]);
        }

        if ($target->disabled_at !== null) {
            throw ValidationException::withMessages(['version' => "Version {$version} is disabled; it can't be restored."]);
        }

        if ($version === $secret->current_version) {
            throw ValidationException::withMessages(['version' => "Version {$version} is already the current version."]);
        }

        return ($this->set)($secret, $this->cipher->open($secret, $target), $userId, restoredFrom: $version);
    }
}
