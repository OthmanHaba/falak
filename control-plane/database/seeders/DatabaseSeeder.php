<?php

namespace Database\Seeders;

use Falak\Identity\Application\Actions\RegisterUser;
use Falak\Identity\Domain\Models\User;
use Illuminate\Database\Seeder;

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
