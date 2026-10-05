<?php

namespace Falak\Identity\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Domain\Models\PersonalAccessToken;
use Falak\Identity\Domain\Models\User;

final class RevokeApiToken
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(User $user, int $tokenId): void
    {
        /** @var PersonalAccessToken $token */
        $token = $user->tokens()->whereKey($tokenId)->firstOrFail();
        $token->delete();

        $this->audit->record('api_token.revoked', 'api_token', (string) $tokenId, ['name' => $token->name], $token->organization_id, $user->id);
    }
}
