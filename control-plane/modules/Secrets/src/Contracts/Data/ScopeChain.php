<?php

namespace Falak\Secrets\Contracts\Data;

use Falak\Secrets\Contracts\SecretScope;

/**
 * The scopes whose secrets a consumer sees: its service, environment, project and organization (ids of
 * Projects services / environments / projects). Missing levels are skipped.
 */
final readonly class ScopeChain
{
    public function __construct(
        public string $organizationId,
        public ?string $projectId = null,
        public ?string $environmentId = null,
        public ?string $serviceId = null,
    ) {}

    /**
     * Nearest first.
     *
     * @return list<array{0: SecretScope, 1: string}>
     */
    public function links(): array
    {
        return array_values(array_filter([
            $this->serviceId !== null ? [SecretScope::Service, $this->serviceId] : null,
            $this->environmentId !== null ? [SecretScope::Environment, $this->environmentId] : null,
            $this->projectId !== null ? [SecretScope::Project, $this->projectId] : null,
            [SecretScope::Organization, $this->organizationId],
        ]));
    }
}
