<?php

use Falak\Builds\Application\BuildConfiguration;
use Falak\Projects\Contracts\VariableReferences;
use Falak\Secrets\Contracts\AccessorType;
use Falak\Secrets\Contracts\Data\ScopeChain;
use Falak\Secrets\Contracts\Data\SecretAccessor;
use Falak\Secrets\Contracts\Secrets;
use Falak\Secrets\Contracts\SecretScope;
use Falak\Secrets\Domain\Models\AccessLogEntry;
use Falak\Sites\Contracts\SiteDirectory;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = memberOf();
    $this->environment = projects_default_env($this->organization);
});

it('resolves only the build variables, logged as the build', function () {
    $site = projects_site($this->organization, 'web', ['VITE_KEY' => '${{ secrets.PUBLIC_KEY }}', 'DB_PASSWORD' => '${{ secrets.DB_PASS }}'], $this->environment);
    secrets_create($this->organization, 'PUBLIC_KEY', 'pk_live', attributes: ['sensitive' => false]);
    secrets_create($this->organization, 'DB_PASS', 'hunter2');
    $configuration = app(BuildConfiguration::class);

    $env = $configuration->environment(app(SiteDirectory::class)->find($site->id), SecretAccessor::build('01k6build00000000000000000'));
    $configuration->environment(app(SiteDirectory::class)->find($site->id), SecretAccessor::build('01k6build00000000000000000'));

    expect($env)->toBe(['VITE_KEY' => 'pk_live']);
    $log = AccessLogEntry::query()->sole();
    expect($log->secret_name)->toBe('PUBLIC_KEY')->and($log->actor_type)->toBe(AccessorType::Build)->and($log->actor_id)->toBe('01k6build00000000000000000');
});

it('refuses a sensitive secret in a public front-end variable', function () {
    $site = projects_site($this->organization, 'web', ['NEXT_PUBLIC_STRIPE' => 'x${{ secrets.STRIPE_SECRET }}'], $this->environment);
    secrets_create($this->organization, 'STRIPE_SECRET', 'sk_live');

    expect(fn () => app(BuildConfiguration::class)->environment(app(SiteDirectory::class)->find($site->id)))
        ->toThrow(RuntimeException::class, 'NEXT_PUBLIC_STRIPE would put a sensitive secret into the public front-end build');
});

it('keeps secrets not available to previews out of preview resolutions', function () {
    $site = projects_site($this->organization, 'web', [], $this->environment);
    secrets_create($this->organization, 'SHARED', 'org-value', attributes: ['available_to_previews' => true]);
    secrets_create($this->organization, 'PROD_ONLY', 'prod');
    // The nearest secret decides: the project's PREVIEWABLE is not available, so the organization's is not used.
    secrets_create($this->organization, 'PREVIEWABLE', 'org', attributes: ['available_to_previews' => true]);
    secrets_create($this->organization, 'PREVIEWABLE', 'project', SecretScope::Project, $this->environment->project_id);

    $variables = ['A' => '${{ secrets.SHARED }}', 'B' => '${{ secrets.PROD_ONLY }}', 'C' => '${{ secrets.PREVIEWABLE }}'];
    $preview = app(VariableReferences::class)->resolveForSite($site->id, $variables, forPreview: true);

    expect($preview->variables['A'])->toBe('org-value')
        ->and($preview->errors)->toHaveCount(2)
        ->and($preview->errors[0])->toContain('PROD_ONLY is not available to preview environments')
        ->and(app(VariableReferences::class)->resolveForSite($site->id, $variables)->ok())->toBeTrue()
        ->and(app(Secrets::class)->check(new ScopeChain($this->organization->id), ['PROD_ONLY'], forPreview: true)->ok())->toBeFalse();
});
