<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kiln\Deployments\Domain\Models\Release;
use Kiln\Deployments\Tests\Support\FakeAnnotations;
use Kiln\Deployments\Tests\Support\FakeBuildService;
use Kiln\Deployments\Tests\Support\FakeEdgeRoutes;
use Kiln\Deployments\Tests\Support\FakeProcessControl;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Domain\Models\Organization;
use Kiln\Identity\Domain\Models\User;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Sites\Contracts\TargetRole;
use Kiln\Sites\Contracts\TargetStatus;
use Kiln\Sites\Domain\Models\EnvironmentVersion;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Domain\Models\SiteTarget;
use Kiln\Sites\Tests\Support\FakeSourceControlGateway;
use Kiln\SourceControl\Contracts\SourceControlGateway;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../../../Sites/tests/Support/helpers.php';

final class DeployWorld
{
    /**
     * @param  list<Server>  $servers  leader first
     */
    public function __construct(
        public User $user,
        public Organization $organization,
        public array $servers,
        public Site $site,
        public FakeAgentGateway $agents,
        public FakeBuildService $builds,
        public FakeEdgeRoutes $edge,
        public FakeAnnotations $annotations,
        public FakeSourceControlGateway $sourceControl,
        public FakeProcessControl $processes,
    ) {}

    public function serverIds(): array
    {
        return array_map(fn (Server $s) => $s->id, $this->servers);
    }
}

const DEPLOY_LARAVEL_SCRIPT = <<<'SH'
    echo "before fetch"
    $KILN_FETCH

    cd "$KILN_RELEASE_DIR"
    if [ "$KILN_IS_LEADER" = "1" ]; then
        $KILN_PHP artisan migrate --force
    fi
    $KILN_PHP artisan optimize

    $KILN_ACTIVATE
    echo "activated"
    $KILN_RESTART_PROCS
    echo "done"
    SH;

/**
 * A site on N ready servers (leader first) with fakes for every collaborator.
 *
 * @param  array<string, mixed>  $site  Site attribute overrides
 */
function deploy_world(int $servers = 1, array $site = [], Role $role = Role::Owner, bool $actingAs = true): DeployWorld
{
    [$user, $organization] = $actingAs ? actingAsMember($role) : memberOf(role: $role);

    $agents = FakeAgentGateway::install();
    $builds = FakeBuildService::install();
    $edge = FakeEdgeRoutes::install();
    $annotations = FakeAnnotations::install();
    $sourceControl = new FakeSourceControlGateway;
    app()->instance(SourceControlGateway::class, $sourceControl);
    $connection = $sourceControl->addConnection($organization->id);

    $processes = FakeProcessControl::install();

    // Health checks answer 200 unless a test maps a URL prefix to another status with deploy_http().
    $GLOBALS['deploy_http'] = [];
    $GLOBALS['deploy_http_requests'] = [];
    Http::fake(function ($request, array $options = []) {
        $GLOBALS['deploy_http_requests'][] = ['url' => $request->url(), 'options' => $options];

        foreach ($GLOBALS['deploy_http'] as $prefix => $status) {
            if (str_starts_with($request->url(), $prefix)) {
                if ($status === 0) { // no HTTP answer (TLS handshake / connection failure)
                    throw new ConnectionException('cURL error 35: TLS connect error');
                }

                return Http::response('status '.$status, $status);
            }
        }

        return Http::response('ok', 200);
    });

    $models = [];

    for ($i = 1; $i <= $servers; $i++) {
        $models[] = sites_server($organization->id, ['name' => "web-{$i}", 'ipv4' => "203.0.113.{$i}"], docker: true);
    }

    $model = Site::query()->forceCreate([
        'id' => strtolower((string) Str::ulid()),
        'organization_id' => $organization->id,
        'name' => 'Shop',
        'slug' => 'shop-'.strtolower(Str::random(5)),
        'runtime' => 'frankenphp',
        'build_mode' => 'native',
        'framework' => 'laravel',
        'php_version' => '8.4',
        'source_connection_id' => $connection->id,
        'repository' => 'acme/shop',
        'branch' => 'main',
        'push_to_deploy' => false,
        'web_directory' => 'public',
        'unix_user' => 'kiln',
        'deploy_script' => DEPLOY_LARAVEL_SCRIPT,
        'laravel' => ['scheduler' => true],
        'shared_paths' => [['path' => 'storage', 'type' => 'directory'], ['path' => '.env', 'type' => 'file']],
        'health_check_path' => '/up',
        'test_domain_enabled' => false,
        ...$site,
    ]);

    foreach ($models as $index => $server) {
        SiteTarget::query()->create([
            'site_id' => $model->id,
            'server_id' => $server->id,
            'role' => $index === 0 ? TargetRole::Leader : TargetRole::Member,
            'status' => TargetStatus::Ready,
        ]);
    }

    EnvironmentVersion::query()->create([
        'site_id' => $model->id,
        'version' => 1,
        'variables' => ['APP_ENV' => 'production', 'APP_KEY' => 'base64:secret', 'KILN_SITE_ID' => 'stale'],
        'exposed' => ['APP_ENV'],
        'changed_keys' => [],
        'created_at' => now(),
    ]);

    return new DeployWorld($user, $organization, $models, $model->refresh(), $agents, $builds, $edge, $annotations, $sourceControl, $processes);
}

/**
 * Commands the fake agent has not finished yet.
 *
 * @return list<array<string, mixed>>
 */
function deploy_pending(FakeAgentGateway $agents, ?string $type = null, ?string $serverId = null): array
{
    return array_values(array_filter($agents->dispatched($type, $serverId), fn (array $c) => ! $agents->commands[$c['handle']->id]['status']->isTerminal()));
}

/**
 * A plausible `$defs.result` for a command.
 *
 * @param  array<string, mixed>  $command
 * @return array<string, mixed>
 */
function deploy_result(array $command): array
{
    $payload = $command['payload'];

    return match ($command['handle']->type) {
        'deploy.fetch' => ['changed' => true, 'release_dir' => '/srv/kiln/sites/x/releases/'.$payload['release_id']],
        'deploy.prepare' => ['changed' => true],
        'deploy.hook', 'system.exec' => ['exit_code' => 0, 'duration_ms' => 5],
        'deploy.activate' => array_filter(['changed' => true, 'release_id' => $payload['release_id'], 'previous_release_id' => deploy_current_release_upper($payload['site'])]),
        'deploy.rollback' => ['changed' => true, 'to_release_id' => $payload['release_id'] ?? 'X', 'from_release_id' => deploy_current_release_upper($payload['site'])],
        'deploy.container.swap' => ['changed' => true, 'active_color' => 'green', 'upstream' => '127.0.0.1:'.$payload['ports']['green']],
        'deploy.prune' => ['changed' => false, 'removed' => []],
        'proc.restart' => ['restarted' => []],
        'docker.compose.pull' => ['exit_code' => 0],
        'docker.compose.up' => ['exit_code' => 0, 'services' => [
            ['service' => 'app', 'container_id' => 'c-app', 'state' => 'running', 'health' => 'healthy', 'image' => 'app', 'image_digest' => 'sha256:'.str_repeat('1', 64), 'restarts' => 0],
            ['service' => 'redis', 'container_id' => 'c-redis', 'state' => 'running', 'image' => 'redis:7.4.1-alpine', 'image_digest' => 'sha256:'.str_repeat('2', 64), 'restarts' => 0],
        ]],
        default => [],
    };
}

function deploy_current_release_upper(string $slug): ?string
{
    $siteId = Site::query()->where('slug', $slug)->value('id');
    $id = $siteId ? Release::current($siteId)?->id : null;

    return $id ? strtoupper($id) : null;
}

/**
 * Succeed pending commands (optionally filtered) once. Returns how many.
 */
function deploy_complete(FakeAgentGateway $agents, ?string $type = null, ?string $serverId = null): int
{
    $pending = deploy_pending($agents, $type, $serverId);

    foreach ($pending as $command) {
        if (! $agents->commands[$command['handle']->id]['status']->isTerminal()) {
            $agents->succeed($command['handle'], deploy_result($command));
        }
    }

    return count($pending);
}

/**
 * Keep succeeding every pending command until none is left.
 */
function deploy_run_all(FakeAgentGateway $agents): void
{
    for ($i = 0; $i < 50 && deploy_complete($agents) > 0; $i++) {
        // each completion may dispatch the next steps
    }
}

/**
 * @return list<string> command types in dispatch order (optionally for one server)
 */
function deploy_types(FakeAgentGateway $agents, ?string $serverId = null): array
{
    return array_map(fn (array $c) => $c['handle']->type, $agents->dispatched(null, $serverId));
}

/**
 * Health check answers by URL prefix, e.g. ['http://203.0.113.2' => 500].
 *
 * @param  array<string, int>  $statuses
 */
function deploy_http(array $statuses): void
{
    $GLOBALS['deploy_http'] = $statuses;
}
