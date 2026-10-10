<?php

namespace Falak\Secrets\Application\Actions;

use Falak\Alerting\Contracts\Alerts;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\OrganizationDirectory;
use Falak\Secrets\Application\AccessRecorder;
use Falak\Secrets\Application\SecretCipher;
use Falak\Secrets\Contracts\Data\SecretAccessor;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Secrets\Domain\Models\SecretVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Read a value back (a linked secret: its reference). Sensitive secrets are write-only and never revealed. The
 * caller checks permission and re-authentication; this logs the read (access log) and audits it (name only).
 *
 * More than secrets.reveal_alert.count reveals by one user within secrets.reveal_alert.minutes alert once per burst
 * (secrets.unusual_reveals): a compromised session or token reading everything.
 */
final class RevealSecret
{
    public function __construct(
        private readonly SecretCipher $cipher,
        private readonly AccessRecorder $recorder,
        private readonly AuditLog $audit,
        private readonly Alerts $alerts,
        private readonly OrganizationDirectory $directory,
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
        $this->watch($secret, $accessor);

        return ['version' => $number, 'value' => $value];
    }

    private function watch(Secret $secret, SecretAccessor $accessor): void
    {
        if ($accessor->userId === null) {
            return;
        }

        $limit = (int) config('secrets.reveal_alert.count', 20);
        $minutes = (int) config('secrets.reveal_alert.minutes', 10);
        $key = "secrets:reveals:{$secret->organization_id}:{$accessor->userId}";

        // Alerts on the reveal that crosses the limit; the burst's later reveals stay quiet until the window passes.
        if (RateLimiter::hit($key, $minutes * 60) !== $limit + 1) {
            return;
        }

        $user = $this->directory->findUser($accessor->userId);
        $who = $user?->email ?? $accessor->userId;

        $this->alerts->raise(new AlertData(
            $secret->organization_id,
            'secrets.unusual_reveals',
            Severity::Warning,
            "{$who} revealed more than {$limit} secrets in {$minutes} minutes",
            "Latest: {$secret->name} ({$accessor->reason}".($accessor->ip ? ", from {$accessor->ip}" : '').'). If this was not expected, revoke the session or API token and rotate what was read.',
            '/settings/audit-log',
            'secrets.unusual_reveals:'.$accessor->userId.':'.now()->format('YmdHi'),
            context: ['user_id' => $accessor->userId, 'ip' => $accessor->ip],
            action: 'Review the audit log',
        ));
    }
}
