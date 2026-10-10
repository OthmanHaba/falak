<?php

use Falak\Identity\Application\Actions\ConfirmTwoFactor;
use Falak\Identity\Application\Actions\CreateApiToken;
use Falak\Identity\Application\Actions\CreateOrganization;
use Falak\Identity\Application\Actions\EnableTwoFactor;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Identity\Domain\Models\PersonalAccessToken;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

it('lists only the abilities the user holds and the tokens of the current organization', function () {
    [$user, $organization] = actingAsMember(Role::Viewer);
    $other = app(CreateOrganization::class)($user, 'Side project');
    app(CreateApiToken::class)($user, $other->id, 'elsewhere', ['*']);
    $user->forceFill(['current_organization_id' => $organization->id])->save();
    app(CreateApiToken::class)($user, $organization->id, 'here', ['members.view']);

    $this->withSession(['identity.current_organization_id' => $organization->id])
        ->get('/settings/api-tokens')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Identity/settings/api-tokens', false)
            ->has('tokens', 1)
            ->where('tokens.0.name', 'here')
            ->where('abilities', fn ($abilities) => collect($abilities)->pluck('name')->contains('members.view') && ! collect($abilities)->pluck('name')->contains('members.manage'))
            ->where('plainTextToken', null));
});

it('creates a token and shows the plain text once', function () {
    [$user, $organization] = actingAsMember(Role::Admin);
    $this->withSession(['identity.reauthenticated_at' => time()]);

    $response = $this->post('/settings/api-tokens', ['name' => 'CI', 'abilities' => ['members.view', 'audit.view'], 'expires_in_days' => 30]);
    $response->assertRedirect()->assertSessionHas('plainTextToken');

    $token = PersonalAccessToken::query()->sole();
    expect($token->organization_id)->toBe($organization->id)
        ->and($token->abilities)->toBe(['members.view', 'audit.view'])
        ->and($token->expires_at->isFuture())->toBeTrue();

    $audit = AuditEntry::query()->where('action', 'api_token.created')->sole();
    expect(json_encode($audit->context))->not->toContain(session('plainTextToken'));
});

it('refuses abilities the user does not hold', function () {
    actingAsMember(Role::Viewer);
    $this->withSession(['identity.reauthenticated_at' => time()]);

    $this->post('/settings/api-tokens', ['name' => 'x', 'abilities' => ['members.manage']])->assertSessionHasErrors('abilities');
    $this->post('/settings/api-tokens', ['name' => 'x', 'abilities' => []])->assertSessionHasErrors('abilities');

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('accepts the wildcard ability', function () {
    actingAsMember(Role::Developer);
    $this->withSession(['identity.reauthenticated_at' => time()]);

    $this->post('/settings/api-tokens', ['name' => 'all', 'abilities' => ['*']])->assertSessionHasNoErrors();

    expect(PersonalAccessToken::query()->sole()->abilities)->toBe(['*']);
});

it('needs a recent re-authentication (with the 2FA code when enabled) to create a token', function () {
    [$user] = actingAsMember(Role::Admin);

    $this->post('/settings/api-tokens', ['name' => 'CI', 'abilities' => ['*']])->assertRedirect('/confirm-password');
    $this->postJson('/settings/api-tokens', ['name' => 'CI', 'abilities' => ['*']])->assertStatus(423);
    // An old confirmation does not count.
    $this->withSession(['identity.reauthenticated_at' => time() - 3600])->post('/settings/api-tokens', ['name' => 'CI', 'abilities' => ['*']])->assertRedirect('/confirm-password');
    expect(PersonalAccessToken::query()->count())->toBe(0);

    app(EnableTwoFactor::class)($user);
    $key = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    app(ConfirmTwoFactor::class)($user, $code = app(Google2FA::class)->getCurrentOtp($key));
    Cache::forget('fortify.2fa_codes.'.md5($code));

    // With 2FA on, Laravel's plain password confirmation is not enough, nor is the password alone.
    $this->withSession(['auth.password_confirmed_at' => time()])->post('/settings/api-tokens', ['name' => 'CI', 'abilities' => ['*']])->assertRedirect('/confirm-password');
    $this->get('/confirm-password')->assertInertia(fn (Assert $page) => $page->where('requiresCode', true));
    $this->post('/confirm-password', ['password' => 'password'])->assertSessionHasErrors('code');
    $this->post('/confirm-password', ['password' => 'password', 'code' => $code])->assertSessionHasNoErrors()->assertRedirect();

    $this->post('/settings/api-tokens', ['name' => 'CI', 'abilities' => ['*']])->assertSessionHas('plainTextToken');
});

it('revokes only the users own tokens', function () {
    [$user, $organization] = actingAsMember();
    $mine = app(CreateApiToken::class)($user, $organization->id, 'mine', ['*']);
    [$other] = memberOf($organization, Role::Developer);
    $theirs = app(CreateApiToken::class)($other, $organization->id, 'theirs', ['*']);

    $this->delete("/settings/api-tokens/{$theirs->accessToken->id}")->assertNotFound();
    $this->delete("/settings/api-tokens/{$mine->accessToken->id}")->assertRedirect();

    expect(PersonalAccessToken::query()->pluck('name')->all())->toBe(['theirs'])
        ->and(AuditEntry::query()->where('action', 'api_token.revoked')->exists())->toBeTrue();
});

it('authenticates API requests with a token pinned to its organization', function () {
    [$user, $organization] = memberOf(role: Role::Developer);
    $token = app(CreateApiToken::class)($user, $organization->id, 'cli', ['members.view']);

    // Switching the web session to another organization must not affect the token.
    $other = app(CreateOrganization::class)($user, 'Other org');
    expect($user->fresh()->current_organization_id)->toBe($other->id);

    $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.organization.id', $organization->id)
        ->assertJsonPath('data.organization.role', 'developer')
        ->assertJsonPath('data.token.abilities', ['members.view']);
});

it('rejects unauthenticated and expired tokens', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();

    [$user, $organization] = memberOf();
    $token = app(CreateApiToken::class)($user, $organization->id, 'old', ['*'], now()->addDay());
    $this->travel(2)->days();

    $this->withToken($token->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
});

it('stops working when the member leaves the organization', function () {
    [$user, $organization] = memberOf(role: Role::Developer);
    $token = app(CreateApiToken::class)($user, $organization->id, 'cli', ['*']);
    $this->actingAs($user)->delete("/organization/members/{$user->id}");
    app('auth')->forgetGuards();

    expect(PersonalAccessToken::query()->count())->toBe(0);
    $this->withToken($token->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
});

it('requires both the token ability and the role permission', function () {
    [$user, $organization] = memberOf(role: Role::Admin);
    $access = app(OrganizationAccess::class);

    $limited = app(CreateApiToken::class)($user, $organization->id, 'limited', ['members.view']);
    $user->withAccessToken($limited->accessToken);
    expect($access->can($user, $organization->id, 'members.view'))->toBeTrue()
        ->and($access->can($user, $organization->id, 'audit.view'))->toBeFalse();

    $wildcard = app(CreateApiToken::class)($user, $organization->id, 'wild', ['*']);
    $user->withAccessToken($wildcard->accessToken);
    expect($access->can($user, $organization->id, 'audit.view'))->toBeTrue()
        // The role still caps a wildcard token.
        ->and($access->can($user, $organization->id, 'organization.delete'))->toBeFalse();

    $elsewhere = app(CreateOrganization::class)($user, 'Mine');
    expect($access->can($user, $elsewhere->id, 'members.view'))->toBeFalse();
});
