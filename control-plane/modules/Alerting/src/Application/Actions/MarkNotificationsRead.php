<?php

namespace Kiln\Alerting\Application\Actions;

use Kiln\Alerting\Domain\Models\Notification;

final class MarkNotificationsRead
{
    /**
     * Mark one notification (or all of the user's unread ones in the organization) as read.
     *
     * @return int rows updated
     */
    public function __invoke(string $userId, string $organizationId, ?string $notificationId = null): int
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->whereNull('read_at')
            ->when($notificationId, fn ($query, $id) => $query->whereKey($id))
            ->update(['read_at' => now()]);
    }
}
