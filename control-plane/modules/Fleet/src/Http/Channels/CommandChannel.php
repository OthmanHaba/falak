<?php

namespace Falak\Fleet\Http\Channels;

use Falak\Fleet\Domain\Models\Command;
use Falak\Identity\Contracts\OrganizationAccess;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * private-fleet.commands.{commandId}: members of the command's organization with fleet.commands.view.
 * Commands with private output (terminal.*) are never joinable here; their module streams them itself.
 */
final class CommandChannel
{
    public const NAME = 'fleet.commands.{commandId}';

    public function __construct(private readonly OrganizationAccess $access) {}

    public function join(Authenticatable $user, string $commandId): bool
    {
        $command = Command::query()->find($commandId, ['id', 'type', 'organization_id']);

        return $command !== null
            && ! $command->hasPrivateOutput()
            && $this->access->can($user, $command->organization_id, 'fleet.commands.view');
    }
}
