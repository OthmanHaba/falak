<?php

use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Kiln\Identity\Application\Actions\ConfirmTwoFactor;
use Kiln\Identity\Application\Actions\EnableTwoFactor;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Domain\Models\AuditEntry;
use Kiln\Identity\Domain\Models\User;
use Kiln\Identity\Events\OrganizationCreated;
use Kiln\Identity\Events\UserRegistered;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

function enableTwoFactorFor(User $user): string
{
    app(EnableTwoFactor::class)($user);
    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    app(ConfirmTwoFactor::class)($user, app(Google2FA::class)->getCurrentOtp($secret));

    return $secret;
}

it('registers a user with a personal organization, owner role and audit entry', function () {
    Event::fake([UserRegistered::class, OrganizationCreated::class]);

    $this->post('/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect('/projects');

    $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
    $this->assertAuthenticatedAs($user);

    $organization = $user->organizations()->sole();
    expect($organization->personal)->toBeTrue()
        ->and($organization->owner_id)->toBe($user->id)
        ->and($organization->name)->toBe("Ada Lovelace's Organization")
        ->and($user->current_organization_id)->toBe($organization->id)
        ->and(app(OrganizationAccess::class)->roleOf($user->id, $organization->id))->toBe(Role::Owner);

    expect(AuditEntry::query()->where('action', 'organization.created')->where('organization_id', $organization->id)->exists())->toBeTrue();
    Event::assertDispatched(UserRegistered::class, fn ($event) => $event->userId === $user->id);
    Event::assertDispatched(OrganizationCreated::class);
});

it('rejects duplicate emails on registration', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post('/register', [
        'name' => 'X', 'email' => 'taken@example.com', 'password' => 'password', 'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');
});

it('logs in users without two-factor directly and audits it', function () {
    [$user, $organization] = memberOf();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/projects');

    $this->assertAuthenticatedAs($user);
    // Logins are personal: recorded without an organization so org admins never see members' login activity.
    expect(AuditEntry::query()->where('action', 'auth.login')->where('actor_id', $user->id)->whereNull('organization_id')->exists())->toBeTrue()
        ->and(AuditEntry::query()->where('action', 'auth.login')->where('organization_id', $organization->id)->exists())->toBeFalse();
});

it('sends users with two-factor to the challenge instead of logging in', function () {
    [$user] = memberOf();
    enableTwoFactorFor($user);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $user->id);

    $this->assertGuest();

    $this->get('/two-factor-challenge')->assertInertia(fn (Assert $page) => $page->component('Identity/auth/two-factor-challenge', false));
});

it('redirects the challenge page to login without a pending challenge', function () {
    $this->get('/two-factor-challenge')->assertRedirect(route('login'));
    $this->post('/two-factor-challenge', ['code' => '123456'])->assertRedirect(route('login'));
});

it('completes the challenge with a valid TOTP code', function () {
    [$user] = memberOf();
    $secret = enableTwoFactorFor($user);
    // Fortify remembers used codes to prevent replays; forget the one used for confirmation.
    cache()->flush();

    $this->withSession(['login.id' => $user->id, 'login.remember' => false])
        ->post('/two-factor-challenge', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertRedirect('/projects');

    $this->assertAuthenticatedAs($user);
    expect(AuditEntry::query()->where('action', 'auth.login')->latest('id')->first()->context)->toBe(['two_factor' => 'totp']);
});

it('completes the challenge with a recovery code and consumes it', function () {
    [$user] = memberOf();
    enableTwoFactorFor($user);
    $code = $user->fresh()->recoveryCodes()[0];

    $this->withSession(['login.id' => $user->id])
        ->post('/two-factor-challenge', ['recovery_code' => $code])
        ->assertRedirect('/projects');

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->recoveryCodes())->not->toContain($code)->toHaveCount(8);
});

it('rejects invalid codes and throttles repeated failures', function () {
    [$user] = memberOf();
    enableTwoFactorFor($user);

    $this->withSession(['login.id' => $user->id])
        ->post('/two-factor-challenge', ['recovery_code' => 'not-a-code'])
        ->assertSessionHasErrors('recovery_code');

    foreach (range(1, 4) as $ignored) {
        $this->withSession(['login.id' => $user->id])
            ->post('/two-factor-challenge', ['code' => '000000'])
            ->assertSessionHasErrors(['code' => 'The provided two factor authentication code was invalid.']);
    }

    $response = $this->withSession(['login.id' => $user->id])->post('/two-factor-challenge', ['code' => '000000']);
    $response->assertSessionHasErrors('code');
    expect(session('errors')->first('code'))->toContain('Too many');

    $this->withSession(['login.id' => $user->id])->post('/two-factor-challenge', ['recovery_code' => 'still-guessing'])
        ->assertSessionHasErrors('recovery_code');
    expect(session('errors')->first('recovery_code'))->toContain('Too many');

    $this->assertGuest();
});

it('requires either a code or a recovery code', function () {
    [$user] = memberOf();
    enableTwoFactorFor($user);

    $this->withSession(['login.id' => $user->id])->post('/two-factor-challenge', [])->assertSessionHasErrors(['code', 'recovery_code']);
});
