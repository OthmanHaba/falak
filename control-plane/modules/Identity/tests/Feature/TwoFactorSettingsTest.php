<?php

use Inertia\Testing\AssertableInertia as Assert;
use Falak\Identity\Domain\Models\AuditEntry;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    [$this->user] = actingAsMember();
});

it('shows the disabled state', function () {
    $this->get('/settings/two-factor')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Identity/settings/two-factor', false)
        ->where('enabled', false)
        ->where('pending', false)
        ->where('qrCodeSvg', null)
        ->where('recoveryCodes', null));
});

it('requires a recent password confirmation to enable', function () {
    $this->post('/settings/two-factor')->assertRedirect(route('password.confirm'));

    expect($this->user->fresh()->two_factor_secret)->toBeNull();
});

it('enables, confirms, reveals, regenerates and disables two-factor', function () {
    $this->withSession(['auth.password_confirmed_at' => time()]);

    $this->post('/settings/two-factor')->assertRedirect();
    $user = $this->user->fresh();
    expect($user->two_factor_secret)->not->toBeNull()->and($user->two_factor_confirmed_at)->toBeNull();

    $this->get('/settings/two-factor')->assertInertia(fn (Assert $page) => $page
        ->where('enabled', false)
        ->where('pending', true)
        ->where('qrCodeSvg', fn ($svg) => str_contains($svg, '<svg'))
        ->where('setupKey', Fortify::currentEncrypter()->decrypt($user->two_factor_secret)));

    $this->post('/settings/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrorsIn('confirmTwoFactorAuthentication', 'code');
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();

    $code = app(Google2FA::class)->getCurrentOtp(Fortify::currentEncrypter()->decrypt($user->two_factor_secret));
    $this->post('/settings/two-factor/confirm', ['code' => $code])->assertSessionHas('twoFactorRevealCodes', true);
    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();

    $this->withSession(['twoFactorRevealCodes' => true])->get('/settings/two-factor')
        ->assertInertia(fn (Assert $page) => $page->where('enabled', true)->has('recoveryCodes', 8)->where('qrCodeSvg', null));

    $this->post('/settings/two-factor/reveal')->assertSessionHas('twoFactorRevealCodes', true);

    $before = $user->fresh()->recoveryCodes();
    $this->post('/settings/two-factor/recovery-codes')->assertSessionHas('twoFactorRevealCodes', true);
    expect($user->fresh()->recoveryCodes())->not->toBe($before);

    $this->delete('/settings/two-factor')->assertRedirect();
    $user = $user->fresh();
    expect($user->two_factor_secret)->toBeNull()->and($user->hasTwoFactorEnabled())->toBeFalse();

    expect(AuditEntry::query()->where('subject_id', $user->id)->pluck('action')->all())
        ->toContain('two_factor.enrollment_started', 'two_factor.enabled', 'two_factor.recovery_codes_regenerated', 'two_factor.disabled');
});

it('refuses to regenerate recovery codes when two-factor is off', function () {
    $this->withSession(['auth.password_confirmed_at' => time()])
        ->post('/settings/two-factor/recovery-codes')
        ->assertStatus(422);
});

it('validates the confirmation code format', function () {
    $this->post('/settings/two-factor/confirm', ['code' => '12'])->assertSessionHasErrors('code');
});
