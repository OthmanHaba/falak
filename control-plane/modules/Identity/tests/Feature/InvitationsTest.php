<?php

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Kiln\Identity\Application\Notifications\OrganizationInvitation;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Domain\Models\Invitation;
use Kiln\Identity\Domain\Models\User;

function invitationUrl(string $email): string
{
    $url = null;

    Notification::assertSentOnDemand(OrganizationInvitation::class, function (OrganizationInvitation $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$url) {
        if ($notifiable->routes['mail'] === $email) {
            $url = $notification->url;
        }

        return true;
    });

    return (string) $url;
}

beforeEach(fn () => Notification::fake());

it('invites by email and lists pending invitations', function () {
    [, $organization] = actingAsMember(Role::Admin);

    $this->post('/organization/invitations', ['email' => 'New@Example.com', 'role' => 'developer'])->assertSessionHasNoErrors();

    $invitation = Invitation::query()->sole();
    expect($invitation->email)->toBe('new@example.com')
        ->and($invitation->role)->toBe(Role::Developer)
        ->and($invitation->expires_at->isAfter(now()->addDays(6)))->toBeTrue();
    expect(invitationUrl('new@example.com'))->toStartWith(url('/invitations/'));

    $mail = (new OrganizationInvitation($organization->name, 'Ann', Role::Developer, 'https://x'))->toMail(new AnonymousNotifiable);
    expect($mail->subject)->toContain($organization->name);

    $this->get('/settings/members')->assertInertia(fn (Assert $page) => $page->has('invitations', 1)->where('invitations.0.email', 'new@example.com'));
});

it('validates invitations', function () {
    [$owner, $organization] = actingAsMember();
    [$member] = memberOf($organization, Role::Viewer);

    $this->post('/organization/invitations', ['email' => $member->email, 'role' => 'viewer'])->assertSessionHasErrors('email');
    $this->post('/organization/invitations', ['email' => 'a@b.co', 'role' => 'owner'])->assertSessionHasErrors('role');
    $this->post('/organization/invitations', ['email' => 'not-an-email', 'role' => 'viewer'])->assertSessionHasErrors('email');

    $this->actingAs($member)->post('/organization/invitations', ['email' => 'x@y.co', 'role' => 'viewer'])->assertForbidden();
    Notification::assertNothingSent();
});

it('replaces an outstanding invitation when re-inviting', function () {
    actingAsMember();

    $this->post('/organization/invitations', ['email' => 'dup@example.com', 'role' => 'viewer']);
    $this->post('/organization/invitations', ['email' => 'dup@example.com', 'role' => 'admin']);

    expect(Invitation::query()->count())->toBe(1)
        ->and(Invitation::query()->sole()->role)->toBe(Role::Admin);
});

it('accepts an invitation with the matching account', function () {
    [, $organization] = actingAsMember();
    $this->post('/organization/invitations', ['email' => 'joiner@example.com', 'role' => 'developer']);
    $url = invitationUrl('joiner@example.com');

    $joiner = User::factory()->withPersonalOrganization()->create(['email' => 'joiner@example.com']);
    $this->actingAs($joiner)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Identity/invitations/show', false)
        ->where('invitation.organization', $organization->name)
        ->where('invitation.email_matches', true));

    $this->post($url)->assertRedirect(route('dashboard'));

    expect(app(OrganizationAccess::class)->roleOf($joiner->id, $organization->id))->toBe(Role::Developer)
        ->and($joiner->fresh()->current_organization_id)->toBe($organization->id)
        ->and(Invitation::query()->sole()->accepted_at)->not->toBeNull();

    // Single use.
    $this->post($url)->assertSessionHasErrors('invitation');
});

it('rejects invitations for a different email address', function () {
    [, $organization] = actingAsMember();
    $this->post('/organization/invitations', ['email' => 'right@example.com', 'role' => 'viewer']);
    $url = invitationUrl('right@example.com');

    $wrong = User::factory()->create(['email' => 'wrong@example.com']);
    $this->actingAs($wrong)->get($url)->assertInertia(fn (Assert $page) => $page->where('invitation.email_matches', false));
    $this->post($url)->assertSessionHasErrors('invitation');

    expect(app(OrganizationAccess::class)->isMember($wrong->id, $organization->id))->toBeFalse();
});

it('rejects expired and revoked invitations', function () {
    actingAsMember();
    $this->post('/organization/invitations', ['email' => 'late@example.com', 'role' => 'viewer']);
    $url = invitationUrl('late@example.com');
    $late = User::factory()->create(['email' => 'late@example.com']);

    $this->travel(8)->days();
    $this->actingAs($late)->get($url)->assertInertia(fn (Assert $page) => $page->where('invitation', null));
    $this->post($url)->assertSessionHasErrors('invitation');
});

it('revokes pending invitations', function () {
    [$owner] = actingAsMember();
    $this->post('/organization/invitations', ['email' => 'gone@example.com', 'role' => 'viewer']);
    $invitation = Invitation::query()->sole();
    $url = invitationUrl('gone@example.com');

    $this->delete("/organization/invitations/{$invitation->id}")->assertRedirect();
    expect(Invitation::query()->count())->toBe(0);

    $this->actingAs(User::factory()->create(['email' => 'gone@example.com']))->post($url)->assertSessionHasErrors('invitation');
});

it('requires authentication to view an invitation', function () {
    $this->get('/invitations/whatever')->assertRedirect(route('login'));
});
