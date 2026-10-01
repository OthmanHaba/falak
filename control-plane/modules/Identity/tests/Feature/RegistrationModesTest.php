<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Domain\Models\User;

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

it('treats an unknown setting as closed', function () {
    config(['identity.registration' => 'yes please']);
    User::factory()->create();

    signUp('stranger@example.com')->assertForbidden();
});

it('only lets invited addresses register in invite mode', function () {
    config(['identity.registration' => 'invite']);
    actingAsMember(Role::Admin);
    $this->post('/organization/invitations', ['email' => 'invited@example.com', 'role' => 'developer'])->assertSessionHasNoErrors();
    Auth::logout();

    $this->get('/register')->assertInertia(fn (Assert $page) => $page->component('Identity/auth/register', false)->where('inviteOnly', true));
    signUp('stranger@example.com')->assertSessionHasErrors('email');
    expect(User::query()->where('email', 'stranger@example.com')->exists())->toBeFalse();

    signUp('invited@example.com')->assertRedirect('/projects');
    expect(User::query()->where('email', 'invited@example.com')->exists())->toBeTrue();
});
