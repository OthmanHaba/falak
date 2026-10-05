<?php

namespace Falak\Alerting\Http\Channels;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * private-alerting.users.{userId}: only the user themself.
 */
final class UserNotificationsChannel
{
    public const NAME = 'alerting.users.{userId}';

    public function join(Authenticatable $user, string $userId): bool
    {
        return (string) $user->getAuthIdentifier() === $userId;
    }
}
