<?php

namespace Falak\Identity\Http\Controllers\Settings;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Falak\Identity\Application\Actions\ConfirmTwoFactor;
use Falak\Identity\Application\Actions\DisableTwoFactor;
use Falak\Identity\Application\Actions\EnableTwoFactor;
use Falak\Identity\Application\Actions\RegenerateRecoveryCodes;
use Falak\Identity\Domain\Models\User;
use Falak\Kernel\Http\Controller;
use Laravel\Fortify\Fortify;

final class TwoFactorController extends Controller
{
    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $pending = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;
        $revealCodes = $request->session()->get('twoFactorRevealCodes', false) && $user->two_factor_recovery_codes !== null;

        return Inertia::render('Identity/settings/two-factor', [
            'enabled' => $user->hasTwoFactorEnabled(),
            'pending' => $pending,
            'qrCodeSvg' => $pending ? $user->twoFactorQrCodeSvg() : null,
            'setupKey' => $pending ? Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret) : null,
            'recoveryCodes' => $revealCodes ? $user->recoveryCodes() : null,
        ]);
    }

    public function store(Request $request, EnableTwoFactor $enable): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $enable($user);

        return back();
    }

    public function confirm(Request $request, ConfirmTwoFactor $confirm): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:6']]);

        /** @var User $user */
        $user = $request->user();
        $confirm($user, $data['code']);

        return back()->with('twoFactorRevealCodes', true);
    }

    public function recoveryCodes(Request $request, RegenerateRecoveryCodes $regenerate): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasTwoFactorEnabled(), 422);

        $regenerate($user);

        return back()->with('twoFactorRevealCodes', true);
    }

    public function reveal(): RedirectResponse
    {
        return back()->with('twoFactorRevealCodes', true);
    }

    public function destroy(Request $request, DisableTwoFactor $disable): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $disable($user);

        return back();
    }
}
