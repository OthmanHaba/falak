<?php

namespace Kiln\Terminal\Http\Channels;

use Illuminate\Contracts\Auth\Authenticatable;
use Kiln\Identity\Contracts\OrganizationDirectory;
use Kiln\Terminal\Domain\Models\TerminalSession;
use Kiln\Terminal\Domain\Policies\TerminalSessionPolicy;

/**
 * presence-terminal.sessions.{sessionId}.{epoch}: the owner, plus members with terminal.attach while the session is shared.
 * Member info tells every participant who may type. Only the current epoch can be joined (it rotates
 * on unshare, cutting off watchers that joined while the session was shared).
 */
final class TerminalSessionChannel
{
    public const NAME = 'terminal.sessions.{sessionId}.{epoch}';

    public function __construct(
        private readonly TerminalSessionPolicy $policy,
        private readonly OrganizationDirectory $directory,
    ) {}

    /**
     * @return array{id: string, name: string, can_type: bool}|false
     */
    public function join(Authenticatable $user, string $sessionId, string $epoch = '0'): array|false
    {
        $session = TerminalSession::query()->find($sessionId);

        if (! $session || (string) $session->channel_epoch !== $epoch || ! $this->policy->canView($user, $session)) {
            return false;
        }

        $id = (string) $user->getAuthIdentifier();

        return [
            'id' => $id,
            'name' => $this->directory->findUser($id)->name ?? 'Member',
            'can_type' => $this->policy->canType($user, $session),
        ];
    }
}
