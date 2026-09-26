<?php

namespace Kiln\Identity\Contracts;

/**
 * Append-only audit trail. Other modules call this for every security-relevant change, e.g.
 * `$audit->record('server.created', subjectType: 'server', subjectId: $server->id, context: [...])`.
 *
 * The actor (user / API token / system) and request metadata are captured automatically;
 * the organization defaults to {@see CurrentOrganization}. Never put secrets in $context.
 */
interface AuditLog
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $context = [],
        ?string $organizationId = null,
        ?string $actorId = null,
    ): void;

    /**
     * Record a user-level event that belongs to no organization (logins, 2FA, account deletion),
     * so it never shows up in an organization's audit log.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordPersonal(string $action, string $userId, array $context = []): void;
}
