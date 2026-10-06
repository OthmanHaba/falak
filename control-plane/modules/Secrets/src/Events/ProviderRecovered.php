<?php

namespace Falak\Secrets\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A secret provider that failed answers again (clears {@see ProviderUnreachable}).
 */
final class ProviderRecovered implements Alertable
{
    use Dispatchable;

    public const ALERT_TYPE = 'secrets.provider_recovered';

    public function __construct(
        public string $organizationId,
        public string $providerId,
        public string $providerName,
    ) {}

    public function toAlert(): AlertData
    {
        return new AlertData(
            $this->organizationId,
            self::ALERT_TYPE,
            Severity::Info,
            "Secret provider {$this->providerName} is reachable again",
            '',
            '/settings/secrets/providers',
            ProviderUnreachable::dedupKey($this->providerId),
            resolves: true,
            context: ['provider_id' => $this->providerId],
        );
    }
}
