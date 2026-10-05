<?php

namespace Falak\Functions\Application\Actions;

use Falak\Deployments\Contracts\DeploymentTrigger;
use Falak\Functions\Application\AgentSupport;
use Falak\Functions\Domain\Models\FunctionVersion;
use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Contracts\Data\SiteData;
use Illuminate\Validation\ValidationException;

/**
 * Versions → Deploy this version: put an earlier (or the newest) version live again. The version list is unchanged.
 */
final class DeployVersion
{
    public function __construct(
        private readonly DeploymentTrigger $deployments,
        private readonly AuditLog $audit,
        private readonly AgentSupport $agents,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(SiteData $site, FunctionVersion $version, ?string $userId, ?string $userName, bool $rollback = true): string
    {
        if (($blocker = $this->agents->blocker($site, $version->files)) !== null) {
            throw ValidationException::withMessages(['version' => ucfirst($blocker)]);
        }

        $deploymentId = $this->deployments->deploy($site->id, $userId, $version->hash, $rollback ? "Roll back to v{$version->number}".($version->message ? ": {$version->message}" : '') : DeployCode::title($version), $userName ?? $version->author_name);
        $this->audit->record('function.version.deployed', 'site', $site->id, ['version' => $version->number, 'hash' => $version->hash, 'deployment_id' => $deploymentId], $site->organizationId);

        return $deploymentId;
    }
}
