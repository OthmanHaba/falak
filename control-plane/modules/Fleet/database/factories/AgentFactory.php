<?php

namespace Falak\Fleet\Database\Factories;

use Falak\Fleet\Contracts\AgentStatus;
use Falak\Fleet\Domain\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    protected $model = Agent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => (string) Str::ulid(),
            'server_id' => (string) Str::ulid(),
            'status' => AgentStatus::Online,
            'hostname' => 'web-'.Str::lower(Str::random(4)),
            'arch' => 'amd64',
            'agent_version' => '1.0.0',
            'facts' => null,
            'metrics' => null,
            'enrolled_at' => now(),
            'last_heartbeat_at' => now(),
        ];
    }
}
