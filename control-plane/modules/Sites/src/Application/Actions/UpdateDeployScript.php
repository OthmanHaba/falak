<?php

namespace Kiln\Sites\Application\Actions;

use Kiln\Identity\Contracts\AuditLog;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Events\SiteUpdated;

final class UpdateDeployScript
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(Site $site, string $script): bool
    {
        $script = rtrim(str_replace("\r\n", "\n", $script))."\n";

        if ($script === $site->deploy_script) {
            return false;
        }

        $site->forceFill(['deploy_script' => $script])->save();

        $this->audit->record('site.deploy_script_updated', 'site', $site->id, ['sha256' => hash('sha256', $script)], $site->organization_id);
        SiteUpdated::dispatch($site->id, $site->organization_id, ['deploy_script'], $site->serverIds());

        return true;
    }
}
