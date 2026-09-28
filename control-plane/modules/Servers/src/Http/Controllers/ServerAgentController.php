<?php

namespace Kiln\Servers\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Kiln\Fleet\Contracts\AgentUpgrades;
use Kiln\Fleet\Contracts\Exceptions\AgentUpgradeUnavailable;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\Servers\Domain\Models\Server;

/**
 * Agent upgrades from the Servers pages (organization admins: `fleet.agents.manage`).
 */
final class ServerAgentController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly AgentUpgrades $upgrades,
        private readonly AuditLog $audit,
    ) {}

    public function upgrade(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('view', $server);
        $this->access->authorize($request->user(), $server->organization_id, 'fleet.agents.manage');

        try {
            $upgrade = $this->upgrades->upgrade($server->id, $request->user()?->getAuthIdentifier());
        } catch (AgentUpgradeUnavailable $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->record('server.agent_upgrade', 'server', $server->id, ['to_version' => $upgrade->toVersion], $server->organization_id);

        return back()->with('success', "Upgrading the agent to {$upgrade->toVersion}. It restarts and reconnects in a few seconds.");
    }

    public function upgradeAll(Request $request): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'fleet.agents.manage');

        $queued = $this->upgrades->upgradeOrganization($organizationId, $request->user()?->getAuthIdentifier());

        if ($queued === []) {
            return back()->with('success', 'Every connected agent already runs the latest build.');
        }

        $this->audit->record('server.agents_upgrade', null, null, ['servers' => count($queued), 'to_version' => $queued[0]->toVersion], $organizationId);
        $batch = (int) config('fleet.agent.upgrade.batch_size', 2);

        return back()->with('success', 'Upgrading '.count($queued).' '.str('agent')->plural(count($queued))." to {$queued[0]->toVersion}, {$batch} at a time. The rollout stops if one fails.");
    }
}
