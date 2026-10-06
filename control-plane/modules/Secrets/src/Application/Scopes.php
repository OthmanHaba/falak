<?php

namespace Falak\Secrets\Application;

use Falak\Projects\Contracts\Data\ServiceData;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Secrets\Contracts\Data\ScopeChain;
use Falak\Secrets\Contracts\SecretScope;

/**
 * Secret scopes against Projects: ownership checks, labels, scope chains and the site services under a scope.
 */
final class Scopes
{
    public function __construct(private readonly ProjectDirectory $projects) {}

    /** Whether the scope exists and belongs to the organization. */
    public function belongsTo(string $organizationId, SecretScope $scope, string $scopeId): bool
    {
        return match ($scope) {
            SecretScope::Organization => $scopeId === $organizationId,
            SecretScope::Project => $this->projects->find($scopeId)?->organizationId === $organizationId,
            SecretScope::Environment => $this->projects->environment($scopeId)?->organizationId === $organizationId,
            SecretScope::Service => $this->projects->findService($scopeId)?->organizationId === $organizationId,
        };
    }

    /** The project a scope lies in (null: organization scope, or gone). */
    public function projectOf(SecretScope $scope, string $scopeId): ?string
    {
        return match ($scope) {
            SecretScope::Organization => null,
            SecretScope::Project => $scopeId,
            SecretScope::Environment => $this->projects->environment($scopeId)?->projectId,
            SecretScope::Service => $this->projects->findService($scopeId)?->projectId,
        };
    }

    public function label(SecretScope $scope, string $scopeId): string
    {
        return match ($scope) {
            SecretScope::Organization => 'Organization',
            SecretScope::Project => $this->projects->find($scopeId)->name ?? 'Project',
            SecretScope::Environment => $this->projects->environment($scopeId)->name ?? 'Environment',
            SecretScope::Service => $this->projects->findService($scopeId)->name ?? 'Service',
        };
    }

    /** The chain a site's variables resolve secrets in (null: the site is in no project environment). */
    public function chainForSite(string $siteId): ?ScopeChain
    {
        $service = $this->projects->projectOf(ServiceKind::Site, $siteId);

        return $service === null ? null : self::chainForService($service);
    }

    public static function chainForService(ServiceData $service): ScopeChain
    {
        return new ScopeChain($service->organizationId, $service->projectId, $service->environmentId, $service->id);
    }

    /**
     * The site services that can see a secret of this scope (their own chain includes it).
     *
     * @return list<ServiceData>
     */
    public function siteServicesUnder(string $organizationId, SecretScope $scope, string $scopeId): array
    {
        $environments = match ($scope) {
            SecretScope::Organization => array_merge(...array_map(
                fn ($project) => array_map(fn ($environment) => $environment->id, $this->projects->environments($project->id)),
                $this->projects->forOrganization($organizationId),
            ) ?: [[]]),
            SecretScope::Project => array_map(fn ($environment) => $environment->id, $this->projects->environments($scopeId)),
            SecretScope::Environment => [$scopeId],
            SecretScope::Service => [],
        };

        $services = $scope === SecretScope::Service
            ? array_filter([$this->projects->findService($scopeId)])
            : array_merge(...array_map(fn (string $environment) => $this->projects->servicesIn($environment), $environments) ?: [[]]);

        return array_values(array_filter($services, fn (ServiceData $service) => $service->kind === ServiceKind::Site));
    }
}
