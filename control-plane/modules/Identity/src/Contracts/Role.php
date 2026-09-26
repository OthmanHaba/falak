<?php

namespace Kiln\Identity\Contracts;

/**
 * Organization roles, from most to least privileged.
 */
enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Developer = 'developer';
    case Viewer = 'viewer';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Full access, including billing, deleting the organization and transferring ownership.',
            self::Admin => 'Manage members, teams, credentials and every resource.',
            self::Developer => 'Create and operate servers, sites and deployments.',
            self::Viewer => 'Read-only access.',
        };
    }

    /** Higher rank = more privileges. */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 40,
            self::Admin => 30,
            self::Developer => 20,
            self::Viewer => 10,
        };
    }

    public function outranks(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    /**
     * Roles that can be granted through invitations / role changes (ownership is transferred, not granted).
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [self::Admin, self::Developer, self::Viewer];
    }
}
