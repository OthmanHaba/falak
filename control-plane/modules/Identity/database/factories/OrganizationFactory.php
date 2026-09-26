<?php

namespace Kiln\Identity\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Kiln\Identity\Domain\Models\Organization;
use Kiln\Identity\Domain\Models\User;

/**
 * Raw organization rows. Prefer the CreateOrganization action in tests that need membership and roles.
 *
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'owner_id' => User::factory(),
            'personal' => false,
        ];
    }
}
