<?php

namespace Falak\Identity\Application\Actions;

use Falak\Identity\Domain\Models\User;
use Falak\Identity\Events\UserRegistered;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;

/**
 * Creates a user together with their personal organization.
 */
final class RegisterUser
{
    public function __construct(private readonly CreateOrganization $createOrganization) {}

    public function __invoke(string $name, string $email, string $password): User
    {
        $user = DB::transaction(function () use ($name, $email, $password) {
            $user = User::query()->create([
                'name' => $name,
                'email' => mb_strtolower($email),
                'password' => $password,
            ]);

            ($this->createOrganization)($user, "{$name}'s Organization", personal: true);

            return $user->refresh();
        });

        event(new Registered($user));
        UserRegistered::dispatch($user->id, $user->email);

        return $user;
    }
}
