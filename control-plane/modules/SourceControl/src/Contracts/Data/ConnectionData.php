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
    ) {}
}
