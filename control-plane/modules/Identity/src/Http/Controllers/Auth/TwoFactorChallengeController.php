<?php

namespace Kiln\Identity\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Domain\Models\User;
use Kiln\Kernel\Http\Controller;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/**
 * Second login step for users with confirmed 2FA (TOTP code or single-use recovery code).
 */
final class TwoFactorChallengeController extends Controller
{
    public const SESSION_USER = 'login.id';

    public const SESSION_REMEMBER = 'login.remember';

    public function create(Request $request): Response|RedirectResponse
    {
        if (! $this->challengedUser($request)) {
            return to_route('login');
        }

        return Inertia::render('Identity/auth/two-factor-challenge');
    }

    public function store(Request $request, TwoFactorAuthenticationProvider $provider, AuditLog $audit): RedirectResponse
    {
        $user = $this->challengedUser($request);

        if (! $user) {
            return to_route('login');
        }

        $data = $request->validate([
            'code' => ['nullable', 'string', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'required_without:code'],
        ]);

        $throttleKey = 'two-factor|'.$user->id.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                empty($data['code']) ? 'recovery_code' : 'code' => __('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]),
            ]);
        }

        $valid = false;

        if (! empty($data['code'])) {
            $valid = $provider->verify(Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret), $data['code']);
        } elseif (! empty($data['recovery_code'])) {
            $code = collect($user->recoveryCodes())->first(fn (string $code) => hash_equals($code, $data['recovery_code']));

            if ($code) {
                $user->replaceRecoveryCode($code);
                $valid = true;
            }
        }

        if (! $valid) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                empty($data['code']) ? 'recovery_code' : 'code' => __('The provided two factor authentication code was invalid.'),
            ]);
        }

        RateLimiter::clear($throttleKey);

        Auth::guard('web')->login($user, (bool) $request->session()->pull(self::SESSION_REMEMBER, false));
        $request->session()->forget(self::SESSION_USER);
        $request->session()->regenerate();

        $audit->recordPersonal('auth.login', $user->id, ['two_factor' => empty($data['code']) ? 'recovery_code' : 'totp']);

        return redirect()->intended(config('fortify.home'));
    }

    private function challengedUser(Request $request): ?User
    {
        $id = $request->session()->get(self::SESSION_USER);

        return is_string($id) ? User::query()->find($id) : null;
    }
}
