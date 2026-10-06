<?php

namespace Falak\Secrets\Contracts\Data;

use Falak\Secrets\Contracts\AccessorType;

/**
 * Who or what reads a secret, and why: written to the secret access log with every read.
 */
final readonly class SecretAccessor
{
    public function __construct(
        public AccessorType $type,
        public ?string $id,
        public string $reason,
        public ?string $userId = null,
        public ?string $ip = null,
    ) {}

    public static function deployment(string $deploymentId, ?int $number = null): self
    {
        return new self(AccessorType::Deployment, $deploymentId, $number !== null ? "Deployment #{$number}" : 'Deployment');
    }

    public static function user(string $userId, string $reason, ?string $ip = null): self
    {
        return new self(AccessorType::User, $userId, $reason, $userId, $ip);
    }

    public static function apiToken(string $tokenId, ?string $userId, string $reason, ?string $ip = null): self
    {
        return new self(AccessorType::ApiToken, $tokenId, $reason, $userId, $ip);
    }

    public static function system(string $reason): self
    {
        return new self(AccessorType::System, null, $reason);
    }
}
