<?php

namespace Falak\Servers\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Servers\Domain\Enums\PhpVersionStatus;
use Falak\Servers\Domain\Models\PhpVersion;
use Illuminate\Validation\ValidationException;

/**
 * Removes a PHP version by converging the provisioning plan without it (provision.apply is full desired state).
 */
final class RemovePhpVersion
{
    public function __construct(
        private readonly ApplyProvisioningPlan $apply,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(PhpVersion $php): void
    {
        if ($php->is_default) {
            throw ValidationException::withMessages(['version' => 'Make another version the default before removing this one.']);
        }

        if ($php->status === PhpVersionStatus::Failed) {
            $php->delete();

            return;
        }

        $server = $php->server()->firstOrFail();
        $previous = $php->status;
        $php->forceFill(['status' => PhpVersionStatus::Removing])->save();

        try {
            $commandId = ($this->apply)($server, markProvisioning: false);
        } catch (ValidationException $e) {
            $php->forceFill(['status' => $previous])->save();

            throw $e;
        }

        $php->forceFill(['command_id' => $commandId])->save();

        $this->audit->record('server.php_remove_requested', 'server', $server->id, ['version' => $php->version], $server->organization_id);
    }
}
