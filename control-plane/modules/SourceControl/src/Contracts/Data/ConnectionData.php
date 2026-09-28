<?php

namespace Kiln\SourceControl\Contracts\Data;

use Kiln\SourceControl\Contracts\ProviderType;

/**
 * A source control connection (never carries secrets).
 */
final readonly class ConnectionData
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public ProviderType $provider,
        public string $name,
        public string $authType,
        public ?string $account,
        public ?string $baseUrl,
        /** active | suspended | disconnected (a GitHub App installation suspended or removed on GitHub) */
        public string $status = 'active',
    ) {}

    /** GitHub App connections clone with short-lived installation tokens and need no deploy keys or repo webhooks. */
    public function isGitHubApp(): bool
    {
        return $this->authType === 'app';
    }
}
