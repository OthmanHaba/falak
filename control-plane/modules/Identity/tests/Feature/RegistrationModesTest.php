<?php

use Falak\Identity\Application\Notifications\OrganizationInvitation;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\Invitation;
use Falak\Identity\Domain\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

function signUp(string $email): TestResponse
{
    return test()->post('/register', ['name' => 'New User', 'email' => $email, 'password' => 'password', 'password_confirmation' => 'password']);
}

beforeEach(fn () => Notification::fake());

it('keeps sign-up open by default and tells guest pages so', function () {
    User::factory()->create();

    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('registration', 'open'));
    signUp('anyone@example.com')->assertRedirect('/projects');
});

it('lets the first user register even when sign-up is closed', function () {
    config(['identity.registration' => 'closed']);

    $this->get('/register')->assertOk();
    signUp('first@example.com')->assertRedirect('/projects');
    expect(User::query()->count())->toBe(1);
});

it('closes sign-up once the panel has users', function () {
    config(['identity.registration' => 'closed']);
    User::factory()->create();

    $this->get('/register')->assertRedirect('/login');
    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('registration', 'closed'));
    signUp('stranger@example.com')->assertForbidden();
    expect(User::query()->where('email', 'stranger@example.com')->exists())->toBeFalse();
});

it('serializes sign-ups, so two guests cannot both take the first-account exception', function () {
    config(['identity.registration' => 'closed', 'identity.registration_lock_wait' => 0]);

    // Another sign-up holds the lock (it may be creating the first account): this one waits, then gives up.
    $lock = Cache::lock('identity:registration', 30);
    expect($lock->get())->toBeTrue();
    signUp('second@example.com')->assertSessionHasErrors(['email' => 'Another sign-up is in progress. Try again in a moment.']);
    expect(User::query()->count())->toBe(0);

    $lock->release();
});

it('treats an unknown setting as closed', function () {
    config(['identity.registration' => 'yes please']);
    User::factory()->create();

    signUp('stranger@example.com')->assertForbidden();
});

/** Invite $email as an admin of a new organization; returns the invitation token from the e-mail link. */
function inviteForSignUp(string $email): string
{
    actingAsMember(Role::Admin);
    test()->post('/organization/invitations', ['email' => $email, 'role' => 'developer'])->assertSessionHasNoErrors();
    Auth::logout();

    $token = null;
    Notification::assertSentOnDemand(OrganizationInvitation::class, function (OrganizationInvitation $notification) use (&$token) {
        $token = basename((string) parse_url($notification->url, PHP_URL_PATH));

        return true;
    });

    return (string) $token;
}

function signUpWith(string $email, ?string $invitation): TestResponse
{
    return test()->post('/register', ['name' => 'New User', 'email' => $email, 'password' => 'password', 'password_confirmation' => 'password', 'invitation' => $invitation]);
}

it('needs the invitation link in invite mode, not just an invited address', function () {
    config(['identity.registration' => 'invite']);
    $token = inviteForSignUp('invited@example.com');
    $refusal = 'Sign-up on this Falak needs an invitation: open the link in your invitation email and use the address it was sent to.';

    // Knowing an invited address is not enough, and the message is the same for invited and other addresses.
    signUpWith('invited@example.com', null)->assertSessionHasErrors(['email' => $refusal]);
    signUpWith('stranger@example.com', null)->assertSessionHasErrors(['email' => $refusal]);
    signUpWith('invited@example.com', 'not-a-token')->assertSessionHasErrors(['email' => $refusal]);
    // A real token used for another address is refused too.
    signUpWith('stranger@example.com', $token)->assertSessionHasErrors(['email' => $refusal]);
    expect(User::query()->whereIn('email', ['invited@example.com', 'stranger@example.com'])->exists())->toBeFalse();

    $this->get('/register?invitation='.$token)->assertInertia(fn (Assert $page) => $page->component('Identity/auth/register', false)
        ->where('inviteOnly', true)
        ->where('invitation.token', $token)
        ->where('invitation.email', 'invited@example.com'));
    $this->get('/register?invitation=nope')->assertInertia(fn (Assert $page) => $page->where('invitation', null));

    signUpWith('invited@example.com', $token)->assertRedirect('/projects');

    // Registered and joined the inviting organization in the same request.
    $user = User::query()->where('email', 'invited@example.com')->sole();
    expect($user->organizations()->where('personal', false)->exists())->toBeTrue()
        ->and(Invitation::query()->sole()->accepted_at)->not->toBeNull();
});

it('carries the invitation from the login page an invitation link redirected to', function () {
    config(['identity.registration' => 'invite']);
    $token = inviteForSignUp('invited@example.com');

    $this->get("/invitations/{$token}")->assertRedirect('/login');
    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('invitation', $token));
});
