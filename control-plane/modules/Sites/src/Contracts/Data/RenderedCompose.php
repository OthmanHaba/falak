<?php

namespace Falak\Sites\Contracts\Data;

/**
 * The compose file Falak hands the agent for one release (§1.3): images pinned, public ports published on
 * loopback, falak.* labels on every service.
 */
final readonly class RenderedCompose
{
    /**
     * @param  string  $yaml  rendered compose.yaml (interpolation stays Compose-native: ${VAR} reads the project .env)
     * @param  list<string>  $services
     * @param  array<string, list<string>>  $leaderCommands  service => argv of `falak.deploy.leader_command`
     * @param  array<string, int>  $hostPorts  public service => loopback host port
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $yaml,
        public array $services,
        public array $leaderCommands,
        public array $hostPorts,
        public array $warnings = [],
    ) {}
}
