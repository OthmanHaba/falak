<?php

namespace Kiln\Servers\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Fleet\Contracts\AgentDirectory;
use Kiln\Fleet\Contracts\Data\MetricSample;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\Providers\Contracts\Data\CredentialSummary;
use Kiln\Providers\Contracts\ProviderGateway;
use Kiln\Providers\Contracts\ProviderType;
use Kiln\Servers\Application\Actions\ApplyProvisioningPlan;
use Kiln\Servers\Application\Actions\CreateServer;
use Kiln\Servers\Application\Actions\DeleteServer;
use Kiln\Servers\Application\Actions\RegenerateInstallCommand;
use Kiln\Servers\Application\Actions\RenameServer;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Contracts\ServerType;
use Kiln\Servers\Domain\Models\PhpVersion;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Domain\Models\SshKey;
use Kiln\Servers\Domain\Stack\Stack;
use Kiln\Servers\Http\Requests\StoreServerRequest;

final class ServerController extends Controller
{
    use PresentsServers;

    public const METRIC_RANGES = ['1h' => 1, '6h' => 6, '24h' => 24];

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly AgentDirectory $agents,
    ) {}

    public function index(Request $request): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'servers.view');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(ServerType::class)],
            'status' => ['nullable', Rule::enum(ServerStatus::class)],
        ]);

        $servers = Server::query()
            ->with('phpVersions')
            ->where('organization_id', $organizationId)
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('ipv4', 'like', "%{$search}%")))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderBy('name')
            ->get();

        $agents = $this->agents->forServers($servers->pluck('id')->all());

        return Inertia::render('Servers/Index', [
            'servers' => $servers->map(fn (Server $server) => $this->summary($server, $agents[$server->id] ?? null))->values(),
            'filters' => array_filter($filters),
            'types' => collect(ServerType::cases())->map(fn (ServerType $type) => ['value' => $type->value, 'label' => $type->label()]),
            'can' => ['create' => $this->access->can($request->user(), $organizationId, 'servers.create')],
        ]);
    }

    public function create(Request $request, ProviderGateway $providers): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'servers.create');

        return Inertia::render('Servers/Create', [
            'types' => collect(ServerType::cases())->map(fn (ServerType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
                'components' => $type->allowedComponents(),
                'defaults' => Stack::defaultsFor($type)->toArray(),
            ]),
            'providers' => collect(ProviderType::cases())->map(fn (ProviderType $type) => ['value' => $type->value, 'label' => $type->label(), 'has_api' => $type->hasApi()]),
            'credentials' => collect($providers->credentials($organizationId))->map(fn (CredentialSummary $credential) => [
                'id' => $credential->id,
                'name' => $credential->name,
                'provider' => $credential->provider->value,
            ])->values(),
            'options' => [
                'php_versions' => array_values((array) config('servers.php_versions')),
                'php_runtimes' => [['value' => 'frankenphp', 'label' => 'FrankenPHP'], ['value' => 'fpm', 'label' => 'PHP-FPM + Caddy']],
                'node_versions' => array_map('strval', array_keys((array) config('servers.node_versions'))),
                'databases' => collect((array) config('servers.databases'))->map(fn (array $db, string $key) => ['value' => $key, 'label' => $db['label']])->values(),
                'caches' => collect((array) config('servers.caches'))->map(fn (array $cache, string $key) => ['value' => $key, 'label' => $cache['label']])->values(),
            ],
            'sshKeys' => SshKey::query()->where('organization_id', $organizationId)->orderBy('name')->get(['id', 'name', 'fingerprint']),
            'canManageProviders' => $this->access->can($request->user(), $organizationId, 'providers.manage'),
        ]);
    }

    public function store(StoreServerRequest $request, CreateServer $create): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'servers.create');

        $server = $create($organizationId, $request->user()?->getAuthIdentifier(), $request->validated());

        return to_route('servers.show', $server);
    }

    public function show(Request $request, Server $server): Response
    {
        $this->authorize('view', $server);
        $server->load(['phpVersions', 'sshKeys']);

        $agent = $this->agents->forServer($server->id);
        $canUpdate = $request->user()?->can('update', $server) ?? false;
        $awaitingAgent = in_array($server->status, [ServerStatus::Creating, ServerStatus::Error], true) && ! $agent;
        $canManageAgents = $this->access->can($request->user(), $server->organization_id, 'fleet.agents.manage');

        return Inertia::render('Servers/Show', [
            'server' => [
                ...$this->summary($server, $agent),
                'size' => $server->size,
                'image' => $server->image,
                'provider_server_id' => $server->provider_server_id,
                'ipv6' => $server->ipv6,
                'private_ipv4' => $server->private_ipv4,
                'ssh_port' => $server->ssh_port,
                'timezone' => $server->timezone,
                'stack' => $server->stack->toArray(),
                'os' => $server->os,
                'arch' => $server->arch,
                'cpus' => $server->cpus,
                'memory_bytes' => $server->memory_bytes,
                'disk_bytes' => $server->disk_bytes,
                'provision_command_id' => $server->provision_command_id,
                'provisioned_at' => $server->provisioned_at?->toIso8601String(),
                // Without a live agent nobody can be displaced; otherwise only agent managers see (re)install commands.
                'install_command' => ($canUpdate && $awaitingAgent) || $canManageAgents ? $server->install_command : null,
                'can_regenerate_install_command' => $canManageAgents,
            ],
            'agent' => $agent ? [
                'id' => $agent->id,
                'status' => $agent->status->value,
                'version' => $agent->version,
                'hostname' => $agent->hostname,
                'enrolled_at' => $agent->enrolledAt->format(DATE_ATOM),
                'last_heartbeat_at' => $agent->lastHeartbeatAt?->format(DATE_ATOM),
                'certificate_expires_at' => $agent->certificateExpiresAt?->format(DATE_ATOM),
                'metrics' => $agent->metrics,
                'runtimes' => $agent->facts['runtimes'] ?? (object) [],
                'docker' => $agent->facts['docker'] ?? null,
                'kernel' => $agent->facts['kernel'] ?? null,
            ] : null,
            'metrics' => $this->samples($server, '1h'),
            'php' => $server->phpVersions->map(fn (PhpVersion $php) => $this->phpVersion($php))->values(),
            'phpOptions' => array_values(array_diff((array) config('servers.php_versions'), $server->phpVersions->pluck('version')->all())),
            'sshKeys' => $server->sshKeys->map(fn (SshKey $key) => [
                'id' => $key->id,
                'name' => $key->name,
                'fingerprint' => $key->fingerprint,
                'unix_user' => $key->getRelationValue('pivot')?->getAttribute('unix_user'),
            ])->values(),
            'availableSshKeys' => SshKey::query()->where('organization_id', $server->organization_id)->orderBy('name')->get(['id', 'name', 'fingerprint']),
            'can' => [
                'update' => $canUpdate,
                'delete' => $request->user()?->can('delete', $server) ?? false,
            ],
        ]);
    }

    public function update(Request $request, Server $server, RenameServer $rename): RedirectResponse
    {
        $this->authorize('update', $server);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/', Rule::unique('servers_servers')->where('organization_id', $server->organization_id)->ignore($server->id)],
        ]);

        $rename($server, $data['name']);

        return back();
    }

    public function reprovision(Server $server, ApplyProvisioningPlan $apply): RedirectResponse
    {
        $this->authorize('update', $server);

        abort_if($server->status === ServerStatus::Deleting, 422, 'The server is being deleted.');
        $apply($server);

        return back();
    }

    public function installCommand(Request $request, Server $server, RegenerateInstallCommand $regenerate): RedirectResponse
    {
        $this->authorize('update', $server);

        // A fresh install token re-binds the server to whichever host runs it (the current agent is
        // revoked on enrollment), so it needs the agent-management permission, not just servers.manage.
        $this->access->authorize($request->user(), $server->organization_id, 'fleet.agents.manage');

        abort_if($server->status === ServerStatus::Deleting, 422, 'The server is being deleted.');
        $regenerate($server);

        return back();
    }

    public function destroy(Request $request, Server $server, DeleteServer $delete): RedirectResponse
    {
        $this->authorize('delete', $server);

        $request->validate(['name' => ['required', 'string', Rule::in([$server->name])]], ['name.in' => 'Type the server name to confirm.']);

        $delete($server, destroyAtProvider: $request->boolean('destroy_at_provider', true));

        return to_route('servers.index');
    }

    public function metrics(Request $request, Server $server): JsonResponse
    {
        $this->authorize('view', $server);
        $range = $request->validate(['range' => ['nullable', Rule::in(array_keys(self::METRIC_RANGES))]])['range'] ?? '1h';

        return response()->json(['data' => $this->samples($server, $range)]);
    }

    public function search(Request $request): JsonResponse
    {
        $organizationId = $this->organization->requireId();

        if (! $this->access->can($request->user(), $organizationId, 'servers.view')) {
            return response()->json(['data' => []]);
        }

        $query = (string) $request->string('q');

        return response()->json([
            'data' => Server::query()
                ->where('organization_id', $organizationId)
                ->when($query !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$query}%")->orWhere('ipv4', 'like', "%{$query}%")))
                ->orderBy('name')
                ->limit(10)
                ->get(['id', 'name', 'ipv4', 'type', 'status'])
                ->map(fn (Server $server) => ['id' => $server->id, 'name' => $server->name, 'ipv4' => $server->ipv4, 'type' => $server->type->value, 'status' => $server->status->value]),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function samples(Server $server, string $range): array
    {
        return array_map(
            fn (MetricSample $sample) => $sample->toArray(),
            $this->agents->metrics($server->id, now()->subHours(self::METRIC_RANGES[$range])),
        );
    }
}
