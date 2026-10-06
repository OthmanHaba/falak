<?php

namespace Falak\Identity\Http\Controllers\Auth;

use Falak\Identity\Contracts\Reauthentication;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Re-authentication: the password, plus the two-factor code when 2FA is enabled. Satisfies both Laravel's
 * `password.confirm` and Falak's `reauthenticated` middleware.
 */
class ConfirmablePasswordController extends Controller
{
    public function show(Request $request, Reauthentication $reauthentication): Response
    {
        return Inertia::render('Identity/auth/confirm-password', [
            'requiresCode' => $reauthentication->requiresCode($request->user()),
        ]);
    }

    public function store(Request $request, Reauthentication $reauthentication): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['nullable', 'string', 'max:16']]);

        $reauthentication->confirm($request, (string) $data['password'], $data['code'] ?? null);

        return redirect()->intended(config('fortify.home'));
    }
}
