<?php

namespace Kiln\Sites\Contracts\Data;

/**
 * One service of a parsed compose file.
 */
final readonly class ComposeServiceSummary
{
    /**
     * @param  list<int>  $ports  container ports the service exposes (`ports` targets + `expose`)
     * @param  list<string>  $publishedPorts  raw host port mappings (Kiln replaces them)
     * @param  list<string>  $volumes  named volumes the service mounts
     * @param  list<string>  $bindMounts  host paths the service bind-mounts
     * @param  list<string>  $dependsOn
     * @param  ?string  $leaderCommand  label kiln.deploy.leader_command (run once on the leader before activation)
     */
    public function __construct(
        public string $name,
        public ?string $image,
        public bool $build,
        public array $ports,
        public array $publishedPorts,
        public array $volumes,
        public array $bindMounts,
        public bool $healthcheck,
        public array $dependsOn = [],
        public ?string $leaderCommand = null,
    ) {}

    public function exposes(int $port): bool
    {
        return in_array($port, $this->ports, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'image' => $this->image,
            'build' => $this->build,
            'ports' => $this->ports,
            'published_ports' => $this->publishedPorts,
            'volumes' => $this->volumes,
            'bind_mounts' => $this->bindMounts,
            'healthcheck' => $this->healthcheck,
            'depends_on' => $this->dependsOn,
            'leader_command' => $this->leaderCommand,
        ];
    }
}
