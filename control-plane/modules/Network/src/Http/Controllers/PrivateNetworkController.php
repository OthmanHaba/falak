<?php

namespace Kiln\Network\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\Network\Application\Actions\AddNetworkMember;
use Kiln\Network\Application\Actions\CreatePrivateNetwork;
use Kiln\Network\Application\Actions\DeletePrivateNetwork;
use Kiln\Network\Application\Actions\RemoveNetworkMember;
use Kiln\Network\Application\ConvergePrivateNetwork;
use Kiln\Network\Domain\Models\PrivateNetwork;
use Kiln\Network\Domain\Models\PrivateNetworkMember;
use Kiln\Servers\Contracts\Data\ServerData;
use Kiln\Servers\Contracts\ServerDirectory;

final class PrivateNetworkController extends Controller
{
    use ResolvesServers;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly ServerDirectory $servers,
    ) {}

    public function store(Request $request, CreatePrivateNetwork $create): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'network.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/', Rule::unique('network_private_networks')->where('organization_id', $organizationId)],
            'cidr' => ['nullable', 'string', 'max:18'],
            'listen_port' => ['nullable', 'integer', 'between:1024,65535'],
        ]);

        $network = $create($organizationId, $data);

        return to_route('network.private-networks.show', $network);
    }

    public function show(Request $request, PrivateNetwork $network): Response
    {
        $this->authorize('view', $network);

        $members = $network->members()->get();
        $memberServers = $members->pluck('server_id')->all();

        return Inertia::render('Network/PrivateNetwork', [
            'network' => [
                'id' => $network->id,
                'name' => $network->name,
                'cidr' => $network->cidr,
                'interface' => $network->interface,
                'listen_port' => $network->listen_port,
                'created_at' => $network->created_at->toIso8601String(),
            ],
            'members' => $members->map(function (PrivateNetworkMember $member) {
                $server = $this->servers->find($member->server_id);

                return [
                    'id' => $member->id,
                    'server_id' => $member->server_id,
                    'server_name' => $server?->name ?? $member->server_id,
                    'public_ipv4' => $server?->ipv4,
                    'address' => $member->address,
                    'public_key' => $member->public_key,
                    'key_status' => $member->key_status->value,
                    'status' => $member->status->value,
                    'error' => $member->error,
                    'command_id' => $member->command_id,
                    'applied_at' => $member->applied_at?->toIso8601String(),
                ];
            })->values(),
            'availableServers' => collect($this->servers->forOrganization($network->organization_id, activeOnly: true))
                ->reject(fn (ServerData $server) => in_array($server->id, $memberServers, true))
                ->map(fn (ServerData $server) => ['id' => $server->id, 'name' => $server->name, 'ipv4' => $server->ipv4, 'type_label' => $server->type->label()])
                ->values(),
            'can' => ['manage' => $request->user()?->can('manage', $network) ?? false],
        ]);
    }

    public function apply(Request $request, PrivateNetwork $network, ConvergePrivateNetwork $converge, AuditLog $audit): RedirectResponse
    {
        $this->authorize('manage', $network);

        $converge($network, force: true);
        $audit->record('network.private_network_reapplied', 'private_network', $network->id, [], $network->organization_id);

        return back();
    }

    public function destroy(Request $request, PrivateNetwork $network, DeletePrivateNetwork $delete): RedirectResponse
    {
        $this->authorize('manage', $network);

        $request->validate(['name' => ['required', 'string', Rule::in([$network->name])]], ['name.in' => 'Type the network name to confirm.']);

        $delete($network);

        return to_route('network.index');
    }

    public function addMember(Request $request, PrivateNetwork $network, AddNetworkMember $add): RedirectResponse
    {
        $this->authorize('manage', $network);

        $serverId = (string) $request->validate(['server_id' => ['required', 'string', 'size:26']])['server_id'];
        $server = $this->servers->find($serverId);

        abort_if($server === null || $server->organizationId !== $network->organization_id, 404);

        $add($network, $server);

        return back();
    }

    public function removeMember(Request $request, PrivateNetwork $network, PrivateNetworkMember $member, RemoveNetworkMember $remove): RedirectResponse
    {
        $this->authorize('manage', $network);
        abort_unless($member->network_id === $network->id, 404);

        $member->setRelation('network', $network);
        $remove($member);

        return back();
    }
}
