<?php

namespace Falak\Identity\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Falak\Identity\Application\Actions\AcceptInvitation;
use Falak\Identity\Application\Actions\RegisterUser;
use Falak\Identity\Application\Registration;
use Falak\Identity\Domain\Models\User;
use Falak\Kernel\Http\Controller;

class RegisteredUserController extends Controller
{
    /**
     * Show the registration page.
     */
    public function create(Request $request, Registration $registration): Response|RedirectResponse
    {
        if ($registration->mode() === Registration::CLOSED) {
            return redirect()->route('login')->with('status', 'Sign-up is disabled on this Falak. Ask an administrator for an account.');
        }

        // ?invitation=<token> from the invitation link: carried through the form; the address it was sent to is
        // pre-filled (only the token's holder sees it).
        $token = $request->string('invitation')->toString() ?: null;
        $invitation = $registration->invitationFor($token);

        return Inertia::render('Identity/auth/register', [
            'inviteOnly' => $registration->mode() === Registration::INVITE,
            'invitation' => $invitation ? ['token' => $token, 'email' => $invitation->email, 'organization' => $invitation->organization?->name] : null,
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request, RegisterUser $register, Registration $registration, AcceptInvitation $accept): RedirectResponse
    {
        abort_if($registration->mode() === Registration::CLOSED, 403, 'Sign-up is disabled on this Falak.');

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'invitation' => ['nullable', 'string', 'max:255'],
        ]);

        $email = $request->string('email')->toString();
        $token = $request->string('invitation')->toString() ?: null;

        // Checked again, with the account created, under the sign-up lock: the panel may have got its first user meanwhile.
        $user = $registration->exclusively(function () use ($request, $register, $registration, $email, $token) {
            abort_if($registration->mode() === Registration::CLOSED, 403, 'Sign-up is disabled on this Falak.');

            // One message for every refusal: it must not tell whether an address has been invited.
            if (! $registration->allows($email, $token)) {
                throw ValidationException::withMessages(['email' => 'Sign-up on this Falak needs an invitation: open the link in your invitation email and use the address it was sent to.']);
            }

            return $register($request->string('name')->toString(), $email, $request->string('password')->toString());
        });

        Auth::login($user);
        $request->session()->regenerate();

        if ($registration->invitationFor($token, $email) !== null) {
            $accept($user, (string) $token);
        }

        return redirect(config('fortify.home'));
    }
}
