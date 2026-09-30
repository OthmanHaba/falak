<?php

namespace Kiln\Functions\Application\Actions;

use Illuminate\Validation\ValidationException;
use Kiln\Deployments\Contracts\DeploymentTrigger;
use Kiln\Functions\Application\FunctionStore;
use Kiln\Functions\Application\Starters;
use Kiln\Projects\Contracts\Data\EnvironmentData;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\Data\SitePlacement;
use Kiln\Sites\Contracts\SiteFactory;
use Kiln\Sites\Contracts\SiteRuntime;

/**
 * Canvas → Create → Function: a function site on one server with the chosen starter as version 1, deployed right away.
 */
final class CreateFunction
{
    public function __construct(
        private readonly SiteFactory $sites,
        private readonly FunctionStore $functions,
        private readonly DeploymentTrigger $deployments,
    ) {}

    /**
     * @return array{site: SiteData, deployment_id: ?string, warnings: list<string>}
     *
     * @throws ValidationException
     */
    public function __invoke(EnvironmentData $environment, ?string $userId, ?string $userName, string $name, string $serverId, string $starter, mixed $domain, ?int $x, ?int $y, bool $deploy): array
    {
        Starters::content($starter);

        $created = $this->sites->create($environment->organizationId, $userId, array_filter([
            'name' => $name,
            'framework' => 'docker',
            'runtime' => SiteRuntime::Function->value,
            'server_ids' => [$serverId],
            'domain' => $domain,
        ], fn ($v) => $v !== null), new SitePlacement($environment->projectId, $environment->id, $x, $y));

        $site = $created->site;
        $function = $this->functions->ensure($site, $starter, $userId, $userName);
        $warnings = $created->warnings;
        $deploymentId = null;

        if ($deploy && ($head = $function->head()) !== null) {
            try {
                $deploymentId = $this->deployments->deploy($site->id, $userId, $head->hash, DeployCode::title($head), $userName);
            } catch (ValidationException $e) {
                $warnings[] = 'The function was created, but its first deploy did not start: '.collect($e->errors())->flatten()->first();
            }
        }

        return ['site' => $site, 'deployment_id' => $deploymentId, 'warnings' => $warnings];
    }
}
