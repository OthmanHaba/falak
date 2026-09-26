<?php

namespace Kiln\Edge\Application\Actions;

use Kiln\Edge\Application\EdgeChanges;
use Kiln\Edge\Domain\Models\SiteSetting;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Sites\Contracts\Data\SiteData;

final class UpdateSiteSettings
{
    public function __construct(
        private readonly EdgeChanges $changes,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  list<string>  $allowIps
     * @param  list<string>  $denyIps
     */
    public function __invoke(SiteData $site, array $allowIps, array $denyIps, ?int $maxBodyBytes, bool $encode): void
    {
        $settings = SiteSetting::for($site->id);
        $settings->forceFill([
            'allow_ips' => array_values(array_unique($allowIps)),
            'deny_ips' => array_values(array_unique($denyIps)),
            'max_body_bytes' => $maxBodyBytes ?: null,
            'encode' => $encode,
        ])->save();

        $this->audit->record('edge.settings_updated', 'site', $site->id, [
            'allow_ips' => $settings->allow_ips,
            'deny_ips' => $settings->deny_ips,
            'max_body_bytes' => $settings->max_body_bytes,
            'encode' => $encode,
        ], $site->organizationId);

        $this->changes->siteChanged($site->id);
    }
}
