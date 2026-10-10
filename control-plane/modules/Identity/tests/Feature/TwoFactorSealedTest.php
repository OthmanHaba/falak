<?php

use Falak\Identity\Application\Actions\EnableTwoFactor;
use Falak\Identity\Domain\Models\User;
use Falak\Kernel\Security\DecryptionFailed;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Fortify;

it('seals two-factor secrets and recovery codes under the key hierarchy, not APP_KEY', function () {
    $user = User::factory()->create();
    app(EnableTwoFactor::class)($user);

    $raw = DB::table('identity_users')->where('id', $user->id)->first(['two_factor_secret', 'two_factor_recovery_codes']);

    expect($raw->two_factor_secret)->toStartWith('fk1:')
        ->and($raw->two_factor_recovery_codes)->toStartWith('fk1:')
        ->and(Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret))->toMatch('/^[A-Z2-7]{16,}$/')
        ->and($user->fresh()->recoveryCodes())->toHaveCount(8)
        ->and(fn () => Crypt::decrypt($raw->two_factor_secret))->toThrow(Exception::class);
});

it('binds two-factor secrets to the user: a secret copied to another user does not open', function () {
    $victim = User::factory()->create();
    $attacker = User::factory()->create();
    app(EnableTwoFactor::class)($victim);
    app(EnableTwoFactor::class)($attacker);

    $raw = DB::table('identity_users')->where('id', $victim->id)->first(['two_factor_secret', 'two_factor_recovery_codes']);
    DB::table('identity_users')->where('id', $attacker->id)->update((array) $raw);

    expect(fn () => $attacker->fresh()->two_factor_secret)->toThrow(DecryptionFailed::class)
        ->and(fn () => $attacker->fresh()->two_factor_recovery_codes)->toThrow(DecryptionFailed::class);

    // The two columns are bound apart too.
    DB::table('identity_users')->where('id', $victim->id)->update(['two_factor_recovery_codes' => $raw->two_factor_secret]);
    expect(fn () => $victim->fresh()->two_factor_recovery_codes)->toThrow(DecryptionFailed::class);
});

it('re-encrypts two-factor values written by Fortify with APP_KEY during the v0.10.0 migration', function () {
    $user = User::factory()->create();
    DB::table('identity_users')->where('id', $user->id)->update([
        'two_factor_secret' => Crypt::encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => Crypt::encrypt(json_encode(['code-1', 'code-2'])),
    ]);

    (require base_path('modules/Kernel/database/migrations/2026_10_23_000001_seal_encrypted_columns.php'))->up();

    $user->refresh();
    expect(DB::table('identity_users')->where('id', $user->id)->value('two_factor_secret'))->toStartWith('fk1:')
        ->and(Fortify::currentEncrypter()->decrypt($user->two_factor_secret))->toBe('JBSWY3DPEHPK3PXP')
        ->and($user->recoveryCodes())->toBe(['code-1', 'code-2']);
});
