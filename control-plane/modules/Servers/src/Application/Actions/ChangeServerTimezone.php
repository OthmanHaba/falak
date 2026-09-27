<?php

namespace Kiln\Servers\Application\Actions;

use Kiln\Identity\Contracts\AuditLog;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Domain\Models\Server;

/**
 * Stores the server timezone and, for active servers, converges it through the provisioning plan.
 */
final class ChangeServerTimezone
{
    public function __construct(private readonly AuditLog $audit, private readonly ApplyProvisioningPlan $apply) {}

    /**
     * @return bool whether a provisioning run was queued to apply it
     */
    public function __invoke(Server $server, string $timezone): bool
    {
        $from = $server->timezone;

        if ($from === $timezone) {
            return false;
        }

        $server->forceFill(['timezone' => $timezone])->save();
        $this->audit->record('server.timezone_changed', 'server', $server->id, ['from' => $from, 'to' => $timezone], $server->organization_id);

        if ($server->status !== ServerStatus::Active) {
            return false;
        }

        ($this->apply)($server);

        return true;
    }
}
