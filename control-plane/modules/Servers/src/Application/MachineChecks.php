<?php

namespace Kiln\Servers\Application;

use Kiln\Fleet\Contracts\AgentDirectory;
use Kiln\Servers\Domain\MachineCheck\DecisionEngine;
use Kiln\Servers\Domain\MachineCheck\MachineCheck;
use Kiln\Servers\Domain\MachineCheck\MachineReport;
use Kiln\Servers\Domain\MachineCheck\Wanted;
use Kiln\Servers\Domain\Models\MachineInspection;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Infrastructure\ProvisioningPlanBuilder;

/**
 * Glue between a server, its stored machine check and the pure {@see DecisionEngine}.
 */
final class MachineChecks
{
    /** Agent feature: provision.inspect and provision.apply components. */
    public const FEATURE = 'provision.v2';

    public function __construct(
        private readonly ProvisioningPlanBuilder $plans,
        private readonly AgentDirectory $agents,
    ) {}

    /**
     * Whether the server's agent runs the machine check (older agents provision as before).
     */
    public function supported(Server $server): bool
    {
        return $this->agents->forServer($server->id)?->supports(self::FEATURE) ?? false;
    }

    public function wanted(Server $server): Wanted
    {
        $stack = $server->stack;
        $node = $stack->node !== null ? config("servers.node_versions.{$stack->node}") : null;

        return new Wanted(
            stack: $stack,
            servesHttp: $server->type->servesHttp(),
            customServer: $server->isCustom(),
            hostname: $this->plans->hostname($server->name),
            sshPort: (int) ($server->ssh_port ?? 22),
            swapMb: $this->plans->swapMb($server->memory_bytes),
            phpVersions: $stack->phpRuntime !== null ? ($server->desiredPhpVersions() ?: $stack->phpVersions) : [],
            nodeVersion: $node !== null ? (string) $node : null,
            basePackages: array_values(array_map('strval', (array) config('servers.base_packages', []))),
        );
    }

    /**
     * @param  array<string, mixed>  $report  provision.inspect result
     */
    public function decide(Server $server, array $report): MachineCheck
    {
        return (new DecisionEngine((array) config('servers')))->decide(new MachineReport($report), $this->wanted($server));
    }

    /**
     * Decisions for the next provision.apply: from the stored report against the server's current stack (it may have
     * changed since the check, e.g. a database engine added), for agents that understand them; null = no machine
     * check (today's plan).
     */
    public function current(Server $server): ?MachineCheck
    {
        $inspection = $server->machineInspection;

        if (! $inspection instanceof MachineInspection || $inspection->report === null || ! $this->supported($server)) {
            return null;
        }

        return $this->decide($server, $inspection->report);
    }
}
