<?php

namespace Kiln\Servers\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Kiln\Fleet\Contracts\AgentDirectory;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\Servers\Application\Actions\CreateServer;
use Kiln\Servers\Application\Actions\DeleteServer;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Http\Controllers\PresentsServers;
use Kiln\Servers\Http\Requests\StoreServerRequest;

/**
 * Public API (Sanctum tokens; abilities are permission names).
 */
final class ServerApiController extends Controller
{
    use PresentsServers;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly AgentDirectory $agents,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'servers.view');

        $servers = Server::query()->with('phpVersions')->where('organization_id', $organizationId)->orderBy('name')->get();
        $agents = $this->agents->forServers($servers->pluck('id')->all());

        return response()->json(['data' => $servers->map(fn (Server $server) => $this->summary($server, $agents[$server->id] ?? null))->values()]);
    }

    public function show(Server $server): JsonResponse
    {
        $this->authorize('view', $server);
        $server->load('phpVersions');

        return response()->json(['data' => [
            ...$this->summary($server, $this->agents->forServer($server->id)),
            'stack' => $server->stack->toArray(),
            'php_versions' => $server->phpVersions->map(fn ($php) => $this->phpVersion($php))->values(),
        ]]);
    }

    public function store(StoreServerRequest $request, CreateServer $create): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'servers.create');

        $server = $create($organizationId, $request->user()?->getAuthIdentifier(), $request->validated());
        $server->load('phpVersions');

        return response()->json(['data' => [...$this->summary($server, null), 'install_command' => $server->isCustom() ? $server->install_command : null]], 201);
    }

    public function destroy(Request $request, Server $server, DeleteServer $delete): Response
    {
        $this->authorize('delete', $server);
        $delete($server, destroyAtProvider: $request->boolean('destroy_at_provider', true));

        return response()->noContent(202);
    }
}
