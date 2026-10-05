<?php

namespace Falak\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A user's membership in an organization. The member's role is stored by
 * spatie/laravel-permission, scoped to the organization.
 *
 * @property string $organization_id
 * @property string $user_id
 */
class Membership extends Pivot
{
    protected $table = 'identity_memberships';
}
