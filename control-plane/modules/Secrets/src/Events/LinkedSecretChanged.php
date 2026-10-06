<?php

namespace Falak\Secrets\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A watched linked secret changed at its provider: a new version was recorded and, per the secret's setting,
 * the services using it were restarted or redeployed. Names only, never the value.
 */
final class LinkedSecretChanged implements Alertable
{
    use Dispatchable;

    public const ALERT_TYPE = 'secrets.linked_changed';

    /**
     * @param  string  $onChange  none | restart | redeploy
     * @param  list<string>  $siteIds  the services acted on
     */
    public function __construct(
        public string $organizationId,
        public string $secretId,
        public string $name,
        public int $version,
        public string $onChange,
        public array $siteIds,
    ) {}

    public function toAlert(): AlertData
    {
        $count = count($this->siteIds);
        $action = match ($this->onChange) {
            'restart' => "Restarted {$count} service(s) that use it.",
            'redeploy' => "Redeploying {$count} service(s) that use it.",
            default => 'Services pick it up on their next deployment.',
        };

        return new AlertData(
            $this->organizationId,
            self::ALERT_TYPE,
            Severity::Info,
            "Secret {$this->name} changed upstream (v{$this->version})",
            $action,
            '/settings/secrets',
            context: ['secret_id' => $this->secretId, 'version' => $this->version, 'on_change' => $this->onChange],
        );
    }
}
