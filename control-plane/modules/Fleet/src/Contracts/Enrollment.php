<?php

namespace Kiln\Fleet\Contracts;

use Kiln\Fleet\Contracts\Data\InstallToken;

/**
 * Agent enrollment for other modules (Servers creates install commands and revokes agents).
 */
interface Enrollment
{
    /**
     * Issue a one-time install token. The returned command (`curl -fsSL <url> | sh`) installs and enrolls
     * kiln-agent; AgentEnrolled is dispatched once it enrolls.
     */
    public function issueInstallToken(string $organizationId, ?string $serverId = null, ?int $ttlMinutes = null): InstallToken;

    /**
     * Revoke every agent (and certificate) for the server, invalidate unused install tokens and cancel queued commands.
     */
    public function revokeServer(string $serverId, string $reason): void;
}
