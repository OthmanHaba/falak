<?php

namespace Falak\Identity\Infrastructure;

use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\Reauthentication;
use Falak\Identity\Domain\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

final class SessionReauthentication implements Reauthentication
{
    /** Set only by a confirmation that also checked the 2FA code (when enabled). */
    public const SESSION_KEY = 'identity.reauthenticated_at';

    public function __construct(
        private readonly TwoFactorAuthenticationProvider $twoFactor,
        private readonly AuditLog $audit,
    ) {}

    public function confirmedWithin(Request $request, int $seconds): bool
    {
        $user = $request->user();

        if (! $user instanceof User || ! $request->hasSession()) {
            return false;
        }

        $at = (int) $request->session()->get(self::SESSION_KEY, 0);

        // Without 2FA, the standard password confirmation counts too.
        if (! $this->requiresCode($user)) {
            $at = max($at, (int) $request->session()->get('auth.password_confirmed_at', 0));
        }

        return $at > 0 && time() - $at <= $seconds;
    }

    public function requiresCode(Authenticatable $user): bool
    {
        return $user instanceof User && $user->hasTwoFactorEnabled();
    }

    public function confirm(Request $request, #[\SensitiveParameter] string $password, #[\SensitiveParameter] ?string $code): void
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw ValidationException::withMessages(['password' => __('auth.failed')]);
        }

        // Per user, not per address: the session is already authenticated, so the password is what's guessed.
        $throttleKey = 'reauthenticate|'.$user->id;

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages(['password' => __('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)])]);
        }

        if (! Hash::check($password, $user->getAuthPassword())) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages(['password' => __('auth.password')]);
        }

        if ($this->requiresCode($user)) {
            if ($code === null || $code === ''
                || ! $this->twoFactor->verify(Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret), $code)) {
                RateLimiter::hit($throttleKey);

                throw ValidationException::withMessages(['code' => __('The provided two factor authentication code was invalid.')]);
            }
        }

        RateLimiter::clear($throttleKey);

        $request->session()->put(self::SESSION_KEY, time());
        $request->session()->put('auth.password_confirmed_at', time());

        $this->audit->recordPersonal('auth.reauthenticated', $user->id, ['two_factor' => $this->requiresCode($user)]);
    }
}
