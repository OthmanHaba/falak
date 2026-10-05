<?php

namespace Falak\Identity\Http\Controllers\Auth;

use Falak\Identity\Application\Registration;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Http\Requests\Auth\LoginRequest;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Show the login page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Identity/auth/login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => $request->session()->get('status'),
            // A guest sent here from an invitation link signs up with that invitation.
            'invitation' => Registration::tokenFromUrl($request->session()->get('url.intended')),
        ]);
    }

    /**
     * Handle an incoming authentication request. Users with 2FA are sent to the challenge step.
     */
    public function store(LoginRequest $request, AuditLog $audit): RedirectResponse
    {
        $user = $request->validateCredentials();

        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put([
                TwoFactorChallengeController::SESSION_USER => $user->id,
                TwoFactorChallengeController::SESSION_REMEMBER => $request->boolean('remember'),
            ]);

            return to_route('two-factor.login');
        }

        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $audit->recordPersonal('auth.login', $user->id);

        return redirect()->intended(config('fortify.home'));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
