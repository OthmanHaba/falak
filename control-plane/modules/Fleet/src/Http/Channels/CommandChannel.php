<?php

namespace Kiln\Fleet\Http\Channels;

use Illuminate\Contracts\Auth\Authenticatable;
use Kiln\Fleet\Domain\Models\Command;
use Kiln\Identity\Contracts\OrganizationAccess;

/**
 * private-fleet.commands.{commandId}: members of the command's organization with fleet.commands.view.
 */
final class CommandChannel
{
    public const NAME = 'fleet.commands.{commandId}';

    public function __construct(private readonly OrganizationAccess $access) {}

    public function join(Authenticatable $user, string $commandId): bool
    {
        $organizationId = Command::query()->whereKey($commandId)->value('organization_id');

        return is_string($organizationId) && $this->access->can($user, $organizationId, 'fleet.commands.view');
    }
}
