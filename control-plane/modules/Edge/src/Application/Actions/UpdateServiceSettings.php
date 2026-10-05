<?php

namespace Falak\Edge\Application\Actions;

use Falak\Edge\Application\EdgeChanges;
use Falak\Edge\Domain\Models\ServiceSetting;
use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Contracts\Data\SiteData;

/**
 * IP lists of one public service of a compose site (on top of the site's: the allow list replaces the site's when set,
 * the deny list adds to it). Empty lists remove the service's settings.
 */
final class UpdateServiceSettings
{
    public function __construct(
        private readonly EdgeChanges $changes,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  list<string>  $allowIps
     * @param  list<string>  $denyIps
     */
    public function __invoke(SiteData $site, string $service, array $allowIps, array $denyIps): void
    {
        $allowIps = array_values(array_unique($allowIps));
        $denyIps = array_values(array_unique($denyIps));

        if ($allowIps === [] && $denyIps === []) {
            ServiceSetting::query()->where('site_id', $site->id)->where('service', $service)->delete();
        } else {
            ServiceSetting::for($site->id, $service)->forceFill(['allow_ips' => $allowIps, 'deny_ips' => $denyIps])->save();
        }

        $this->audit->record('edge.settings_updated', 'site', $site->id, ['service' => $service, 'allow_ips' => $allowIps, 'deny_ips' => $denyIps], $site->organizationId);

        $this->changes->siteChanged($site->id);
    }
}
