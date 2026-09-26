<?php

namespace Kiln\Identity\Domain\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Sanctum API token, scoped to one organization. Abilities are Identity permission
 * names (or "*"); a request is authorized only when both the token ability and the
 * owner's role in the token's organization allow it.
 *
 * @property ?string $organization_id
 */
class PersonalAccessToken extends SanctumToken
{
    protected $table = 'identity_personal_access_tokens';

    /** @var list<string> */
    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'organization_id'];
}
