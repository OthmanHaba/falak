<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Kiln\Identity\Application\Actions\RegisterUser;
use Kiln\Identity\Domain\Models\User;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with a local development account.
     */
    public function run(RegisterUser $register): void
    {
        if (User::query()->where('email', 'test@example.com')->exists()) {
            return;
        }

        $register('Test User', 'test@example.com', 'password')->markEmailAsVerified();
    }
}
