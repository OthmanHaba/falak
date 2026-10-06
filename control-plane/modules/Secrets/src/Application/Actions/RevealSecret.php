<?php

namespace Falak\Secrets\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Secrets\Application\AccessRecorder;
use Falak\Secrets\Application\SecretCipher;
use Falak\Secrets\Contracts\Data\SecretAccessor;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Secrets\Domain\Models\SecretVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Read a value back (a linked secret: its reference). Sensitive secrets are write-only and never revealed. The
 * caller checks permission and re-authentication; this logs the read (access log) and audits it (name only).
 */
final class RevealSecret
{
    public function __construct(
        private readonly SecretCipher $cipher,
        private readonly AccessRecorder $recorder,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @return array{version: int, value: string}
     *
     * @throws AuthorizationException when the secret is sensitive
     */
    public function __invoke(Secret $secret, ?int $version, SecretAccessor $accessor): array
    {
        if ($secret->sensitive) {
            throw new AuthorizationException('This secret is sensitive: its value is write-only and can\'t be revealed.');
        }

        $number = $version ?? $secret->current_version;
        $target = SecretVersion::query()->where('secret_id', $secret->id)->where('version', $number)->first();

        if ($target === null) {
            throw ValidationException::withMessages(['version' => "Version {$number} does not exist."]);
        }

        if ($target->disabled_at !== null) {
            throw ValidationException::withMessages(['version' => "Version {$number} is disabled."]);
        }

        $value = $this->cipher->open($secret, $target);

        $this->recorder->record($secret, $number, $accessor);
        $this->audit->record('secret.revealed', 'secret', $secret->id, ['name' => $secret->name, 'version' => $number], $secret->organization_id);

        return ['version' => $number, 'value' => $value];
    }
}
