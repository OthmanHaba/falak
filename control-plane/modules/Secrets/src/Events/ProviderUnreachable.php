<?php

namespace Falak\Secrets\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A secret provider could not be asked for a value (unreachable, refused, not found). Deployments go on with
 * the last good value when there is one ($usedStale); alerts once per provider until it answers again.
 */
final class ProviderUnreachable implements Alertable
{
    use Dispatchable;

    public const ALERT_TYPE = 'secrets.provider_unreachable';

    public function __construct(
        public string $organizationId,
        public string $providerId,
        public string $providerName,
        public string $error,
        public bool $usedStale,
    ) {}

    public static function dedupKey(string $providerId): string
    {
        return "secrets.provider:{$providerId}";
    }

    public function toAlert(): AlertData
    {
        return new AlertData(
            $this->organizationId,
            self::ALERT_TYPE,
            Severity::Warning,
            "Secret provider {$this->providerName} is unreachable",
            $this->error.($this->usedStale ? ' The last good value was used.' : ''),
            '/settings/secrets/providers',
            self::dedupKey($this->providerId),
            context: ['provider_id' => $this->providerId, 'used_stale' => $this->usedStale],
        );
    }
}
