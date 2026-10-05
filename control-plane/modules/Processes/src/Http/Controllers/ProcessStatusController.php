<?php

namespace Falak\Processes\Http\Controllers;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Kernel\Http\Controller;
use Falak\Processes\Application\Actions\RestartSiteProcesses;
use Falak\Processes\Application\StatusPoller;
use Falak\Processes\Domain\Models\ServerState;
use Falak\Sites\Contracts\TargetStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Live proc.status for a site's tabs, and the restart action.
 */
final class ProcessStatusController extends Controller
{
    use ResolvesSite;

    /**
     * POST: ask every ready server of the site for proc.status. The page then polls show().
     */
    public function refresh(Request $request, string $site, StatusPoller $poller): JsonResponse
    {
        $site = $this->site($request, $site);
        $commands = [];

        foreach ($site->targets as $target) {
            if ($target->status !== TargetStatus::Ready || ! ServerState::query()->whereKey($target->serverId)->exists()) {
                continue;
            }

            if ($handle = $poller->request($target->serverId)) {
                $commands[] = ['id' => $handle->id, 'server_id' => $target->serverId];
            }
        }

        return response()->json(['commands' => $commands]);
    }

    /**
     * GET ?commands=id,id: settled status results, limited to the site's own programs.
     */
    public function show(Request $request, string $site, AgentGateway $agents): JsonResponse
    {
        $site = $this->site($request, $site);
        $ids = array_slice(array_filter(explode(',', (string) $request->query('commands', ''))), 0, 50);
        $serverIds = $site->serverIds();
        $results = [];

        foreach ($ids as $id) {
            try {
                $result = $agents->status($id);
            } catch (Throwable) {
                continue;
            }

            if ($result->type !== 'proc.status' || ! in_array($result->serverId, $serverIds, true)) {
                continue;
            }

            $programs = ServerState::query()->find($result->serverId)?->programs ?? [];
            $mine = array_keys(array_filter($programs, fn (array $meta) => ($meta['site_id'] ?? null) === $site->id));

            $results[] = [
                'id' => $result->id,
                'server_id' => $result->serverId,
                'status' => $result->status->value,
                'terminal' => $result->status->isTerminal(),
                'error' => $result->error,
                'processes' => array_values(array_filter(
                    (array) ($result->result['processes'] ?? []),
                    fn ($p) => is_array($p) && in_array($p['name'] ?? null, $mine, true),
                )),
            ];
        }

        return response()->json(['results' => $results]);
    }

    public function restart(Request $request, string $site, RestartSiteProcesses $restart): RedirectResponse|JsonResponse
    {
        $site = $this->site($request, $site, 'processes.manage');
        $data = $request->validate(['server_id' => ['nullable', 'string', 'in:'.implode(',', $site->serverIds())]]);

        $count = $restart($site, $data['server_id'] ?? null);

        if ($request->wantsJson() && $request->header('X-Inertia') === null) {
            return response()->json(['data' => ['commands' => $count]]);
        }

        return back()->with($count > 0 ? 'success' : 'warning', $count > 0 ? 'Restarting the site\'s processes.' : 'Nothing is running for this site yet.');
    }
}
