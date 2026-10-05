<?php

namespace Falak\Network\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Network\Domain\Models\FirewallRule;
use Falak\Network\Domain\Models\FirewallState;
use Falak\Network\Domain\Models\PrivateNetwork;
use Falak\Network\Domain\Models\PrivateNetworkMember;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Servers\Contracts\ServerType;

final class NetworkController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly ServerDirectory $servers,
    ) {}

    public function index(Request $request): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'network.view');

        $servers = $this->servers->forOrganization($organizationId);
        $states = FirewallState::query()->where('organization_id', $organizationId)->get()->keyBy('server_id');
        $ruleCounts = FirewallRule::query()->where('organization_id', $organizationId)->selectRaw('server_id, count(*) as aggregate')->groupBy('server_id')->pluck('aggregate', 'server_id');
        $memberships = PrivateNetworkMember::query()->with('network')->where('organization_id', $organizationId)->orderBy('created_at')->get()->groupBy('server_id');

        $addresses = fn (string $serverId) => ($memberships[$serverId] ?? collect())->map(fn (PrivateNetworkMember $m) => [
            'network_id' => $m->network_id,
            'network' => $m->network->name,
            'address' => $m->address,
            'status' => $m->status->value,
        ])->values();

        return Inertia::render('Network/Index', [
            'servers' => collect($servers)->map(fn (ServerData $server) => [
                'id' => $server->id,
                'name' => $server->name,
                'type' => $server->type->value,
                'type_label' => $server->type->label(),
                'status' => $server->status->value,
                'ipv4' => $server->ipv4,
                'rules_count' => (int) ($ruleCounts[$server->id] ?? 0),
                'firewall' => isset($states[$server->id]) ? FirewallController::presentState($states[$server->id]) : null,
                'private_addresses' => $addresses($server->id),
            ])->values(),
            'networks' => PrivateNetwork::query()->where('organization_id', $organizationId)->withCount('members')->orderBy('name')->get()->map(fn (PrivateNetwork $network) => [
                'id' => $network->id,
                'name' => $network->name,
                'cidr' => $network->cidr,
                'interface' => $network->interface,
                'listen_port' => $network->listen_port,
                'members_count' => (int) $network->getAttribute('members_count'),
            ])->values(),
            'loadBalancers' => collect($servers)->filter(fn (ServerData $server) => $server->type === ServerType::LoadBalancer)->map(fn (ServerData $server) => [
                'id' => $server->id,
                'name' => $server->name,
                'status' => $server->status->value,
                'ipv4' => $server->ipv4,
                'ipv6' => $server->ipv6,
                'private_addresses' => $addresses($server->id),
            ])->values(),
            'defaults' => [
                'cidr' => (string) config('network.private_cidr'),
                'listen_port' => (int) config('network.wireguard_port'),
            ],
            'can' => ['manage' => $this->access->can($request->user(), $organizationId, 'network.manage')],
        ]);
    }
}
