<?php

namespace Falak\Identity\Application\Actions;

use DateTimeInterface;
use Illuminate\Validation\ValidationException;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Domain\Models\User;
use Falak\Identity\Infrastructure\SpatieOrganizationAccess;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issues a Sanctum token pinned to one organization. Abilities are permission names and must be a
 * subset of what the user holds there ("*" = everything the user's role allows, evaluated per request).
 */
final class CreateApiToken
{
    public function __construct(
        private readonly SpatieOrganizationAccess $access,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  list<string>  $abilities
     */
    public function __invoke(User $user, string $organizationId, string $name, array $abilities, ?DateTimeInterface $expiresAt = null): NewAccessToken
    {
        if (! $user->belongsToOrganization($organizationId)) {
            throw ValidationException::withMessages(['name' => 'You are not a member of that organization.']);
        }

        $abilities = array_values(array_unique($abilities));

        if ($abilities === []) {
            throw ValidationException::withMessages(['abilities' => 'Select at least one ability.']);
        }

        if ($abilities !== ['*']) {
            $held = $this->access->permissionsOf($user, $organizationId);
            $excess = array_diff($abilities, $held);

            if ($excess !== []) {
                throw ValidationException::withMessages(['abilities' => 'You cannot grant abilities you do not hold: '.implode(', ', $excess).'.']);
            }
        }

        $token = $user->createToken($name, $abilities, $expiresAt);
        $token->accessToken->forceFill(['organization_id' => $organizationId])->save();

        $this->audit->record('api_token.created', 'api_token', (string) $token->accessToken->getKey(), [
            'name' => $name,
            'abilities' => $abilities,
            'expires_at' => $expiresAt?->format(DATE_ATOM),
        ], $organizationId, $user->id);

        return $token;
    }
}
