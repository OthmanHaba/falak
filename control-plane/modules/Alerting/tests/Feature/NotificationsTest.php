<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Http;
use Kiln\Alerting\Application\Jobs\PruneAlerting;
use Kiln\Alerting\Domain\Models\Alert;
use Kiln\Alerting\Domain\Models\Channel;
use Kiln\Alerting\Domain\Models\Notification;
use Kiln\Alerting\Domain\Models\Rule;
use Kiln\Alerting\Http\Channels\UserNotificationsChannel;
use Kiln\Identity\Application\Actions\AssignRole;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Events\OrganizationDeleted;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    alerting_rule($this->organization->id, ['*']);
});

function notify_many(string $organizationId, int $count): void
{
    foreach (range(1, $count) as $i) {
        event(alerting_issue_opened($organizationId, sprintf('01JISSUE0000000000000000%02d', $i)));
    }
}

it('shows the notification center with an unread filter', function () {
    notify_many($this->organization->id, 3);
    Notification::query()->where('user_id', $this->user->id)->oldest('id')->first()->forceFill(['read_at' => now()])->save();

    $this->get('/notifications')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Alerting/Notifications', false)
        ->has('notifications.data', 3)
        ->where('unreadCount', 2));

    $this->get('/notifications?unread=1')->assertInertia(fn ($page) => $page->has('notifications.data', 2)->where('filters.unread', true));
});

it('returns the unread count and latest notifications as JSON', function () {
    notify_many($this->organization->id, 7);

    $this->getJson('/notifications/unread')->assertOk()
        ->assertJsonPath('count', 7)
        ->assertJsonCount(5, 'latest')
        ->assertJsonPath('latest.0.title', 'New issue: RuntimeException: boom')
        ->assertJsonPath('latest.0.url', url('/insights/issues/01JISSUE000000000000000007'));
});

it('marks one or all notifications as read', function () {
    notify_many($this->organization->id, 3);
    $first = Notification::query()->where('user_id', $this->user->id)->oldest('id')->first();

    $this->postJson("/notifications/{$first->id}/read")->assertOk();
    expect($first->refresh()->read_at)->not->toBeNull();

    $this->postJson('/notifications/read-all')->assertOk()->assertJson(['updated' => 2]);
    $this->getJson('/notifications/unread')->assertJsonPath('count', 0);
});

it('never exposes or updates other users notifications', function () {
    [$other] = memberOf($this->organization, Role::Viewer);
    notify_many($this->organization->id, 1);
    $theirs = Notification::query()->where('user_id', $other->id)->sole();

    $this->postJson("/notifications/{$theirs->id}/read")->assertNotFound();
    expect($theirs->refresh()->read_at)->toBeNull();

    $this->post('/notifications/read-all');
    expect($theirs->refresh()->read_at)->toBeNull();
});

it('scopes notifications to the current organization', function () {
    [, $second] = memberOf();
    $second->members()->attach($this->user->id);
    app(AssignRole::class)($this->user, $second->id, Role::Viewer);
    alerting_rule($second->id, ['*']);
    notify_many($second->id, 2);
    notify_many($this->organization->id, 1);

    $this->getJson('/notifications/unread')->assertJsonPath('count', 1);
});

it('authorizes the private notification channel only for its own user', function () {
    $channel = new UserNotificationsChannel;

    expect($channel->join($this->user, $this->user->id))->toBeTrue()
        ->and($channel->join($this->user, '01JOTHERUSER00000000000001'))->toBeFalse()
        ->and(Broadcast::getChannels()->has(UserNotificationsChannel::NAME))->toBeTrue();
});

it('prunes alerts and notifications past retention', function () {
    config(['alerting.retention_days' => 30]);
    notify_many($this->organization->id, 2);
    Alert::query()->oldest('id')->first()->forceFill(['created_at' => now()->subDays(31)])->save();
    Notification::query()->oldest('id')->first()->forceFill(['created_at' => now()->subDays(31)])->save();
    $notifications = Notification::query()->count();

    (new PruneAlerting)->handle();

    expect(Alert::query()->count())->toBe(1)->and(Notification::query()->count())->toBe($notifications - 1);
});

it('deletes alerting data when the organization is deleted', function () {
    alerting_channel($this->organization->id);
    notify_many($this->organization->id, 1);

    event(new OrganizationDeleted($this->organization->id));

    expect(Channel::query()->count())->toBe(0)->and(Rule::query()->count())->toBe(0)
        ->and(Alert::query()->count())->toBe(0)->and(Notification::query()->count())->toBe(0);
});
