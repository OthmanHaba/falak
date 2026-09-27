<?php

namespace Kiln\Identity\Infrastructure;

use Kiln\Identity\Contracts\Data\OrganizationData;
use Kiln\Identity\Contracts\Data\UserData;
use Kiln\Identity\Contracts\OrganizationDirectory;
use Kiln\Identity\Domain\Models\Organization;
use Kiln\Identity\Domain\Models\User;

final class EloquentOrganizationDirectory implements OrganizationDirectory
{
    public function find(string $organizationId): ?OrganizationData
    {
        return Organization::query()->find($organizationId)?->toData();
    }

    public function all(): array
    {
        return Organization::query()->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (Organization $organization) => $organization->toData())
            ->values()
            ->all();
    }

    public function findUser(string $userId): ?UserData
    {
        $user = User::query()->find($userId);

        return $user ? new UserData($user->id, $user->name, $user->email) : null;
    }

    public function members(string $organizationId): array
    {
        $organization = Organization::query()->find($organizationId);

        if (! $organization) {
            return [];
        }

        return $organization->members()
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => new UserData($user->id, $user->name, $user->email))
            ->values()
            ->all();
    }
}
