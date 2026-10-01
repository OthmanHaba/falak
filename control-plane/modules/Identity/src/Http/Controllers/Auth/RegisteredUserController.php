<?php

namespace Kiln\Identity\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Identity\Application\Actions\RegisterUser;
use Kiln\Identity\Application\Registration;
use Kiln\Identity\Domain\Models\User;
use Kiln\Kernel\Http\Controller;

class RegisteredUserController extends Controller
{
    /**
     * Show the registration page.
     */
    public function create(Registration $registration): Response|RedirectResponse
    {
        if ($registration->mode() === Registration::CLOSED) {
            return redirect()->route('login')->with('status', 'Sign-up is disabled on this Kiln. Ask an administrator for an account.');
        }

        return Inertia::render('Identity/auth/register', ['inviteOnly' => $registration->mode() === Registration::INVITE]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request, RegisterUser $register, Registration $registration): RedirectResponse
    {
        abort_if($registration->mode() === Registration::CLOSED, 403, 'Sign-up is disabled on this Kiln.');

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        if (! $registration->allows($request->string('email')->toString())) {
            throw ValidationException::withMessages(['email' => 'Sign-up needs an invitation: ask an organization admin to invite this address.']);
        }

        $user = $register($request->string('name')->toString(), $request->string('email')->toString(), $request->string('password')->toString());

        Auth::login($user);
        $request->session()->regenerate();

        return redirect(config('fortify.home'));
    }
}
