<?php

namespace Falak\Servers\Http\Controllers;

use Falak\Fleet\Contracts\AgentUpgrades;
use Falak\Fleet\Contracts\Exceptions\AgentUpgradeUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Servers\Domain\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
        $data = $request->validate(['server_ids' => ['sometimes', 'array', 'min:1', 'max:2000'], 'server_ids.*' => ['string', 'size:26']]);

        // Servers of other organizations never match: the rollout is scoped to the current one.
        $queued = $this->upgrades->upgradeOrganization($organizationId, $request->user()?->getAuthIdentifier(), $data['server_ids'] ?? null);

        if ($queued === []) {
            return back()->with('success', isset($data['server_ids']) ? 'The selected agents are offline, already upgrading or up to date.' : 'Every connected agent already runs the latest build.');
        }

        $this->audit->record('server.agents_upgrade', null, null, ['servers' => count($queued), 'to_version' => $queued[0]->toVersion, 'selected' => isset($data['server_ids'])], $organizationId);
        $batch = (int) config('fleet.agent.upgrade.batch_size', 2);

        return back()->with('success', 'Upgrading '.count($queued).' '.str('agent')->plural(count($queued))." to {$queued[0]->toVersion}, {$batch} at a time. The rollout stops if one fails.");
    }
}
