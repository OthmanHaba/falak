<?php

namespace Falak\Alerting\Application;

use Falak\Alerting\Domain\Models\Alert;
use Falak\Alerting\Domain\Models\Notification;
use Falak\Alerting\Events\NotificationCreated;
use Falak\Identity\Contracts\OrganizationAccess;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\UserProvider;

/**
 * Creates notification-center entries for every organization member allowed to see alerts
 * and pushes them over Reverb (private-alerting.users.{userId}).
 */
final class InAppNotifier
{
    public function __construct(
        private readonly OrganizationAccess $access,
        private readonly AuthFactory $auth,
    ) {}

    public function notify(Alert $alert): int
    {
        $provider = $this->provider();
        $count = 0;

        foreach ($this->access->memberIds($alert->organization_id) as $userId) {
            $user = $provider?->retrieveById($userId);

            if (! $user || ! $this->access->can($user, $alert->organization_id, 'alerting.view')) {
                continue;
            }

            $notification = Notification::query()->create([
                'organization_id' => $alert->organization_id,
                'user_id' => $userId,
                'alert_id' => $alert->id,
                'type' => $alert->type,
                'severity' => $alert->severity,
                'title' => $alert->recovery ? "Resolved: {$alert->title}" : $alert->title,
                'body' => $alert->body,
                'url' => $alert->url,
            ]);

            NotificationCreated::dispatch($userId, $alert->organization_id, $notification->toPayload());
            $count++;
        }

        return $count;
    }

    private function provider(): ?UserProvider
    {
        $guard = $this->auth->guard('web');

        return method_exists($guard, 'getProvider') ? $guard->getProvider() : null;
    }
}
