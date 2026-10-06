<?php

use Falak\Deployments\Domain\Enums\DeploymentStatus;
use Falak\Projects\Contracts\VariableReferences;
use Falak\Secrets\Contracts\AccessorType;
use Falak\Secrets\Contracts\SecretScope;
use Falak\Secrets\Domain\Models\AccessLogEntry;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = memberOf();
    $this->environment = projects_default_env($this->organization);
});

it('substitutes ${{ secrets.NAME }} in the scope of the service that owns the variable', function () {
    $web = projects_site($this->organization, 'web', ['STRIPE' => 'sk_${{ secrets.STRIPE_KEY }}', 'API' => '${{ api.TOKEN }}', 'PLAIN' => 'x'], $this->environment);
    $api = projects_site($this->organization, 'api', ['TOKEN' => '${{ secrets.TOKEN }}'], $this->environment);
    secrets_create($this->organization, 'STRIPE_KEY', 'live123', attributes: ['sensitive' => true]);
    secrets_create($this->organization, 'TOKEN', 'org-token', attributes: ['sensitive' => false]);
    // api's own secret wins over the organization's, also when web reads api's variable.
    secrets_create($this->organization, 'TOKEN', 'api-token', SecretScope::Service, secrets_service_of($api), ['sensitive' => false]);

    $result = app(VariableReferences::class)->resolveForSite($web->id, ['STRIPE' => 'sk_${{ secrets.STRIPE_KEY }}', 'API' => '${{ api.TOKEN }}', 'PLAIN' => 'x']);

    expect($result->errors)->toBe([])
        ->and($result->variables)->toBe(['STRIPE' => 'sk_live123', 'API' => 'api-token', 'PLAIN' => 'x'])
        ->and($result->secretKeys)->toBe(['STRIPE', 'API'])
        ->and($result->sensitiveKeys)->toBe(['STRIPE']);
});

it('reports a missing secret clearly, and check() reads nothing', function () {
    $web = projects_site($this->organization, 'web', [], $this->environment);
    secrets_create($this->organization, 'PRESENT', 'value');
    $variables = ['A' => '${{ secrets.MISSING }}', 'B' => '${{ secrets.PRESENT }}'];

    $errors = app(VariableReferences::class)->check($web->id, $variables);
    expect($errors)->toBe(['A: secret MISSING is not defined for this service (in its service, environment, project or organization secrets).'])
        ->and(AccessLogEntry::query()->count())->toBe(0);

    $result = app(VariableReferences::class)->resolveForSite($web->id, $variables);
    expect($result->errors)->toBe($errors)
        ->and($result->variables['A'])->toBe('${{ secrets.MISSING }}')
        ->and(AccessLogEntry::query()->count())->toBe(1);
});

it('does not read secrets when the variables panel opens', function () {
    actingAsMember(organization: $this->organization);
    $web = projects_site($this->organization, 'web', ['A' => '${{ secrets.TOKEN }}', 'B' => '${{ secrets.NOPE }}'], $this->environment);
    secrets_create($this->organization, 'TOKEN', 'value');

    $this->getJson("/sites/{$web->id}/environment")->assertOk()
        ->assertJsonPath('data.current.references', ['A' => '${{ secrets.TOKEN }}', 'B' => '${{ secrets.NOPE }}'])
        ->assertJsonPath('data.current.reference_errors.0', 'B: secret NOPE is not defined for this service (in its service, environment, project or organization secrets).');

    expect(AccessLogEntry::query()->count())->toBe(0);
});

it('renders secrets into the release at deploy time and logs the read as the deployment', function () {
    $world = secrets_deploy_world(['APP_KEY' => 'base64:k', 'STRIPE_SECRET' => '${{ secrets.STRIPE_SECRET }}']);
    secrets_create($world->organization, 'STRIPE_SECRET', 'sk_live_abc', SecretScope::Environment, projects_default_env($world->organization)->id);

    $deployment = secret_store_deploy($world);

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($world->agents->last('deploy.prepare')['payload']['env_file']['content'])->toContain('STRIPE_SECRET=sk_live_abc');

    $log = AccessLogEntry::query()->sole();
    expect($log->actor_type)->toBe(AccessorType::Deployment)
        ->and($log->actor_id)->toBe($deployment->id)
        ->and($log->reason)->toBe("Deployment #{$deployment->number}");
});

it('fails the deployment with a clear error when a secret is missing', function () {
    $world = secrets_deploy_world(['APP_KEY' => 'base64:k', 'STRIPE_SECRET' => '${{ secrets.STRIPE_SECRET }}']);

    $deployment = secret_store_deploy($world);

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->error)->toContain('Unresolved variable references: STRIPE_SECRET: secret STRIPE_SECRET is not defined for this service')
        ->and($world->agents->dispatched('deploy.prepare'))->toBe([]);
});
