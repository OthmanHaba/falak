<?php

namespace Kiln\Deployments\Tests\Support;

use Illuminate\Support\Str;
use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Processes\Contracts\ProcessControl;
use Kiln\Sites\Contracts\SiteDirectory;

/**
 * ProcessControl that restarts every site through one proc.restart per server (so tests can drive
 * and fail restarts through the FakeAgentGateway). `$noPrograms` simulates sites without programs.
 */
final class FakeProcessControl implements ProcessControl
{
    public bool $noPrograms = false;

    /** @var list<array{site: string, server: ?string}> */
    public array $restarts = [];

    public static function install(): self
    {
        $fake = new self;
        app()->instance(ProcessControl::class, $fake);

        return $fake;
    }

    public function restartForSite(string $siteId, ?string $serverId = null, bool $newRelease = true): array
    {
        $this->restarts[] = ['site' => $siteId, 'server' => $serverId];

        if ($this->noPrograms) {
            return [];
        }

        $site = app(SiteDirectory::class)->find($siteId);

        return [app(AgentGateway::class)->dispatch((string) $serverId, 'proc.restart', ['site' => $site->slug], 300, 'processes.restart:'.Str::ulid())];
    }

    public function converge(string ...$serverIds): void {}
}
