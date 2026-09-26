<?php

namespace Kiln\Servers\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Contracts\ServerType;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Domain\Stack\Stack;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    protected $model = Server::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => (string) Str::ulid(),
            'name' => 'web-'.Str::lower(Str::random(6)),
            'type' => ServerType::Web,
            'status' => ServerStatus::Active,
            'provider' => 'custom',
            'ipv4' => fake()->ipv4(),
            'timezone' => 'UTC',
            'stack' => Stack::defaultsFor(ServerType::Web),
            'memory_bytes' => 4 * 1024 ** 3,
            'provisioned_at' => now(),
        ];
    }

    public function type(ServerType $type): static
    {
        return $this->state(fn () => ['type' => $type, 'stack' => Stack::defaultsFor($type)]);
    }

    public function status(ServerStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
