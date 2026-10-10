<?php

use Falak\Databases\Contracts\DatabaseProvisioner;
use Falak\Deployments\Contracts\DeploymentTrigger;
use Falak\Edge\Contracts\PreviewDomains;
use Falak\Identity\Domain\Models\Organization;
use Falak\Previews\Domain\Models\Preview;
use Falak\Previews\Domain\Models\PreviewSettings;
use Falak\Previews\Tests\Support\FakeDatabaseProvisioner;
use Falak\Previews\Tests\Support\FakeDeploymentTrigger;
use Falak\Previews\Tests\Support\FakePreviewDomains;
use Falak\SourceControl\Contracts\Data\PullRequestData;

require_once __DIR__.'/../../../Projects/tests/Support/helpers.php';
require_once __DIR__.'/Fakes.php';

/**
 * Bind the preview doubles (domain, databases, deployments).
 *
 * @return array{domains: FakePreviewDomains, databases: FakeDatabaseProvisioner, deployments: FakeDeploymentTrigger}
 */
function previews_fakes(): array
{
    $fakes = ['domains' => new FakePreviewDomains, 'databases' => new FakeDatabaseProvisioner, 'deployments' => new FakeDeploymentTrigger];
    app()->instance(PreviewDomains::class, $fakes['domains']);
    app()->instance(DatabaseProvisioner::class, $fakes['databases']);
    app()->instance(DeploymentTrigger::class, $fakes['deployments']);

    return $fakes;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function previews_settings(Organization $organization, string $projectId, string $baseEnvironmentId, array $attributes = []): PreviewSettings
{
    return PreviewSettings::query()->forceCreate([
        'organization_id' => $organization->id,
        'project_id' => $projectId,
        'enabled' => true,
        'base_environment_id' => $baseEnvironmentId,
        ...$attributes,
    ]);
}

function previews_pr(int $number = 7, string $sha = 'c', bool $fork = false, string $repository = 'acme/shop'): PullRequestData
{
    return new PullRequestData($repository, $number, 'Checkout v2', "https://github.com/{$repository}/pull/{$number}", 'feature/checkout',
        str_repeat($sha, 40), 'main', 'ada', $fork, $fork ? 'mallory/shop' : $repository);
}

function previews_sole(): Preview
{
    return Preview::query()->sole();
}
