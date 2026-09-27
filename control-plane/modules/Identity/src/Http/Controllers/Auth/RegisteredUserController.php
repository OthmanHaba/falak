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
use Kiln\Identity\Domain\Models\User;
use Kiln\Kernel\Http\Controller;

class RegisteredUserController extends Controller
{
    /**
     * Show the registration page.
     */
    public function create(): Response
    {
        return Inertia::render('Identity/auth/register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request, RegisterUser $register): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = $register($request->string('name')->toString(), $request->string('email')->toString(), $request->string('password')->toString());

        Auth::login($user);
        $request->session()->regenerate();

        return redirect(config('fortify.home'));
    }
}
