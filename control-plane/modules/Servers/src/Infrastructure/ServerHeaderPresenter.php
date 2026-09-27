<?php

namespace Kiln\Servers\Infrastructure;

use Kiln\Fleet\Contracts\AgentDirectory;
use Kiln\Providers\Contracts\ProviderType;
use Kiln\Servers\Contracts\ServerHeaders;
use Kiln\Servers\Domain\Models\Server;

final class ServerHeaderPresenter implements ServerHeaders
{
    public function __construct(private readonly AgentDirectory $agents) {}

    public function for(string $serverId): array
    {
        $server = Server::query()->findOrFail($serverId);
        $agent = $this->agents->forServer($server->id);

        return [
            'id' => $server->id,
            'name' => $server->name,
            'type' => $server->type->value,
            'type_label' => $server->type->label(),
            'status' => $server->status->value,
            'status_message' => $server->status_message,
            'provider' => $server->provider,
            'provider_label' => ProviderType::tryFrom($server->provider)?->label() ?? $server->provider,
            'region' => $server->region,
            'ipv4' => $server->ipv4,
            'agent' => $agent ? [
                'status' => $agent->status->value,
                'last_heartbeat_at' => $agent->lastHeartbeatAt?->format(DATE_ATOM),
            ] : null,
        ];
    }
}
