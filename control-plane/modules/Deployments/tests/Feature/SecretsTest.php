<?php

use Falak\Deployments\Application\Actions\TriggerDeployment;
use Falak\Deployments\Domain\Enums\DeploymentStatus;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\OutputLine;
use Falak\Deployments\Domain\Models\Release;
use Falak\Deployments\Domain\Models\SiteSettings;
use Falak\Fleet\Events\AgentSecretsMissing;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Domain\Models\EnvironmentVersion;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/../Support/helpers.php';

const SECRETS_DOCKER_SITE = ['runtime' => 'docker', 'build_mode' => 'docker', 'framework' => 'docker', 'php_version' => null, 'app_port' => 3100, 'deploy_script' => '$FALAK_FETCH'];

/**
 * @param  array<string, string>  $variables
 */
function secrets_variables(DeployWorld $world, array $variables): void
{
    EnvironmentVersion::query()->create([
        'site_id' => $world->site->id,
        'version' => (int) EnvironmentVersion::query()->where('site_id', $world->site->id)->max('version') + 1,
        'variables' => $variables,
        'exposed' => [],
        'changed_keys' => [],
        'created_at' => now(),
    ]);
}

function secrets_deploy(DeployWorld $world): Deployment
{
    $deployment = app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), Trigger::Manual);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    return $deployment->refresh();
}

function secrets_missing(DeployWorld $world, array $sites): void
{
    $server = $world->servers[0];
    AgentSecretsMissing::dispatch('agent-1', $world->organization->id, $server->id, $sites);
}

it('names the secret variables in the payloads that carry them', function () {
    $world = deploy_world();
    secrets_variables($world, ['APP_ENV' => 'production', 'APP_KEY' => 'base64:secret', 'MAIL_PASSWORD' => 'mail-secret-1', 'AWS_ACCESS_KEY_ID' => 'AKIAEXAMPLE', 'VITE_PUSHER_APP_KEY' => 'public-key']);
    expect(secrets_deploy($world)->status)->toBe(DeploymentStatus::Succeeded);

    $prepare = $world->agents->last('deploy.prepare')['payload'];
    expect($prepare['mask'])->toBe(['APP_KEY', 'MAIL_PASSWORD'])
        // Values are never sent twice: only the names.
        ->and(json_encode($prepare['mask']))->not->toContain('secret');

    // Hooks read the .env too, so they name every secret of the site, not only their own env's.
    $hook = $world->agents->dispatched('deploy.hook')[0]['payload'];
    expect($hook['mask'])->toBe(['APP_KEY', 'MAIL_PASSWORD'])
        ->and((array) $hook['env'])->not->toHaveKey('APP_KEY');
});

it('passes secret variables of a container site as /run/secrets files in the files mode', function () {
    $world = deploy_world(site: SECRETS_DOCKER_SITE);
    secrets_variables($world, ['NODE_ENV' => 'production', 'DATABASE_URL' => 'postgres://app:pw-123456@db/app', 'API_TOKEN' => 'token-123456']);

    secrets_deploy($world);
    $swap = $world->agents->last('deploy.container.swap')['payload'];
    expect($swap['env'])->toHaveKey('DATABASE_URL')
        ->and($swap)->not->toHaveKey('secret_files')
        ->and($swap['mask'])->toBe(['API_TOKEN', 'DATABASE_URL']);

    SiteSettings::for(app(SiteDirectory::class)->find($world->site->id))->forceFill(['secrets_mode' => 'files'])->save();
    $deployment = secrets_deploy($world);
    $swap = $world->agents->last('deploy.container.swap')['payload'];

    expect($deployment->setting('secrets_mode'))->toBe('files')
        ->and((array) $swap['env'])->not->toHaveKey('DATABASE_URL')->not->toHaveKey('API_TOKEN')
        ->and((array) $swap['env'])->toHaveKey('NODE_ENV')
        ->and($swap['secret_files'])->toBe([
            ['name' => 'API_TOKEN', 'content' => 'token-123456'],
            ['name' => 'DATABASE_URL', 'content' => 'postgres://app:pw-123456@db/app'],
        ])
        ->and($swap['mask'])->toBe(['API_TOKEN', 'DATABASE_URL']);
});

it('sends a rebooted server the live release .env again, once per throttle window', function () {
    $world = deploy_world();
    secrets_variables($world, ['APP_ENV' => 'production', 'APP_KEY' => 'base64:released']);
    $deployment = secrets_deploy($world);
    // A later edit does not change what the running release was written with.
    secrets_variables($world, ['APP_ENV' => 'production', 'APP_KEY' => 'base64:edited-later']);

    secrets_missing($world, [$world->site->slug, 'not-a-site-here']);

    $writes = $world->agents->dispatched('site.env.write');
    expect($writes)->toHaveCount(1);
    $payload = $writes[0]['payload'];
    expect($payload['site'])->toBe($world->site->slug)
        ->and($payload['owner'])->toBe(['user' => 'falak'])
        ->and($payload['mask'])->toBe(['APP_KEY'])
        ->and($payload['env_file']['content'])->toContain('APP_KEY=base64:released')
        ->not->toContain('edited-later')
        ->toContain('FALAK_RELEASE_ID='.strtoupper((string) $deployment->release_id))
        ->toContain('FALAK_DEPLOYMENT_ID='.strtoupper($deployment->id))
        ->toContain('FALAK_SERVER_ID='.strtoupper($world->servers[0]->id))
        // Laravel's config cache lives on the tmpfs too: recreated, rebuilt, then PHP and the programs reload.
        ->toContain('APP_CONFIG_CACHE=.falak-cache/config.php')
        ->and($payload['release_id'])->toBe(strtoupper((string) $deployment->release_id))
        ->and($payload['config_cache'])->toBeTrue()
        ->and($payload['after'])->toBe(['script' => 'php8.4 artisan config:cache', 'user' => 'falak'])
        ->and($payload['reload'])->toBe([['kind' => 'frankenphp'], ['kind' => 'site_procs', 'name' => $world->site->slug]]);

    $prepare = $world->agents->last('deploy.prepare')['payload'];
    expect($prepare['config_cache'])->toBeTrue()
        ->and($prepare['env_file']['content'])->toContain('APP_CONFIG_CACHE=.falak-cache/config.php');

    // Heartbeats repeat the report until the files are back.
    secrets_missing($world, [$world->site->slug]);
    expect($world->agents->dispatched('site.env.write'))->toHaveCount(1);

    Cache::flush();
    secrets_missing($world, [$world->site->slug]);
    expect($world->agents->dispatched('site.env.write'))->toHaveCount(2);
});

it('restores a container site secret files only in the files mode', function () {
    $world = deploy_world(site: SECRETS_DOCKER_SITE);
    secrets_variables($world, ['NODE_ENV' => 'production', 'API_TOKEN' => 'token-123456']);
    secrets_deploy($world);

    secrets_missing($world, [$world->site->slug]);
    expect($world->agents->dispatched('site.env.write'))->toBe([]);

    SiteSettings::for(app(SiteDirectory::class)->find($world->site->id))->forceFill(['secrets_mode' => 'files'])->save();
    secrets_deploy($world);
    Cache::flush();
    secrets_missing($world, [$world->site->slug]);

    $live = Release::current($world->site->id);
    $payload = $world->agents->last('site.env.write')['payload'];
    expect($payload)->toBe([
        'site' => $world->site->slug,
        'release_id' => strtoupper($live->id),
        'secret_files' => [['name' => 'API_TOKEN', 'content' => 'token-123456']],
        'mask' => ['API_TOKEN'],
    ])
        // The containers carry the release they run, which the agent matches.
        ->and((array) $world->agents->last('deploy.container.swap')['payload']['labels'])->toMatchArray(['falak.release.id' => strtoupper($live->id)]);
});

it('masks secret values in the stored and broadcast deployment output', function () {
    $world = deploy_world();
    secrets_variables($world, ['APP_ENV' => 'production', 'APP_KEY' => 'base64:hunter2hunter2']);
    $deployment = app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), Trigger::Manual);
    $world->builds->succeed();

    for ($i = 0; $i < 10 && deploy_pending($world->agents, 'deploy.hook') === []; $i++) {
        deploy_complete($world->agents);
    }

    $hook = deploy_pending($world->agents, 'deploy.hook')[0];
    $world->agents->emit($hook['handle'], ["key: base64:hunter2hunter2\n", 'encoded: '.base64_encode('base64:hunter2hunter2')."\n", "env: production\n"]);

    $data = OutputLine::query()->where('deployment_id', $deployment->id)->pluck('data')->implode('');
    expect($data)->not->toContain('hunter2')
        ->not->toContain(rtrim(base64_encode('base64:hunter2hunter2'), '='))
        ->toContain("key: ••••\n")
        ->toContain("env: production\n");
});

it('saves the secrets mode for container sites only', function () {
    $world = deploy_world(site: SECRETS_DOCKER_SITE);
    $settings = fn () => $this->getJson("/sites/{$world->site->id}/deploy-settings", ['X-Falak-Panel' => '1'])->json('data');
    $form = [...$settings()['settings'], 'secrets_mode' => 'files'];

    expect($settings()['settings']['secrets_mode'])->toBe('env')
        ->and($settings()['secretFiles'])->toBeTrue();

    $this->put("/sites/{$world->site->id}/deploy-settings", $form)->assertRedirect();
    expect($settings()['settings']['secrets_mode'])->toBe('files');

    $this->put("/sites/{$world->site->id}/deploy-settings", [...$form, 'secrets_mode' => 'vault'])->assertSessionHasErrors('secrets_mode');

    $classic = deploy_world();
    SiteSettings::for(app(SiteDirectory::class)->find($classic->site->id))->forceFill(['secrets_mode' => 'files'])->save();
    $json = $this->getJson("/sites/{$classic->site->id}/deploy-settings", ['X-Falak-Panel' => '1'])->json('data');
    expect($json['settings']['secrets_mode'])->toBe('env')
        ->and($json['secretFiles'])->toBeFalse();
});

it('waits for a running deployment and ignores sites of other servers', function () {
    $world = deploy_world();
    secrets_variables($world, ['APP_ENV' => 'production', 'APP_KEY' => 'base64:released']);
    secrets_deploy($world);

    // The site is not on this server: nothing to restore there.
    $other = sites_server($world->organization->id, ['name' => 'web-9']);
    AgentSecretsMissing::dispatch('agent-9', $world->organization->id, $other->id, [$world->site->slug]);
    expect($world->agents->dispatched('site.env.write'))->toBe([]);

    // A deployment writes the secrets itself (and may replace the release): ask again on a later heartbeat.
    $running = app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), Trigger::Manual);
    secrets_missing($world, [$world->site->slug]);
    expect($world->agents->dispatched('site.env.write'))->toBe([]);

    $world->builds->succeed();
    deploy_run_all($world->agents);
    expect($running->refresh()->status)->toBe(DeploymentStatus::Succeeded);
    secrets_missing($world, [$world->site->slug]);
    expect($world->agents->last('site.env.write')['payload']['release_id'])->toBe(strtoupper((string) $running->release_id));
});

it('restores a compose project env file from the live release', function () {
    $world = deploy_world(site: [
        'runtime' => 'compose', 'build_mode' => 'docker', 'framework' => 'docker', 'php_version' => null,
        'compose_source' => 'repo', 'compose_file' => null, 'deploy_script' => '', 'app_port' => 3000,
        'public_services' => [['service' => 'app', 'port' => 8080, 'domain' => null, 'host_port' => 3000]],
    ]);
    $world->builds->composeContent = "services:\n  app:\n    build: .\n    ports: ['8080']\n";
    secrets_variables($world, ['APP_ENV' => 'production', 'DB_PASSWORD' => 'pw-123456']);
    $deployment = secrets_deploy($world);
    expect($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($world->agents->last('docker.compose.up')['payload']['mask'])->toBe(['DB_PASSWORD']);

    secrets_missing($world, [$world->site->slug]);
    $payload = $world->agents->last('site.env.write')['payload'];

    expect($payload['compose'])->toBeTrue()
        ->and($payload['release_id'])->toBe(strtoupper((string) $deployment->release_id))
        ->and($payload['env_file']['content'])->toContain('DB_PASSWORD=pw-123456')
        ->toContain('FALAK_SERVER_ID='.strtoupper($world->servers[0]->id))
        ->and($payload['mask'])->toBe(['DB_PASSWORD']);
});
