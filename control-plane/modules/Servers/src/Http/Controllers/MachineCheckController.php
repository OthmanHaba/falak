<?php

namespace Kiln\Servers\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Kiln\Kernel\Http\Controller;
use Kiln\Servers\Application\Actions\ApplyProvisioningPlan;
use Kiln\Servers\Application\Actions\ProvisionServer;
use Kiln\Servers\Application\Actions\RunMachineCheck;
use Kiln\Servers\Application\MachineChecks;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Domain\Models\MachineInspection;
use Kiln\Servers\Domain\Models\Server;

/**
 * The machine check on the server page (Re-check, Provision) and its API: `GET|POST /api/v1/servers/{server}/inspection`,
 * `POST /api/v1/servers/{server}/provision` and `POST /api/v1/servers/{server}/reprovision`.
 */
final class MachineCheckController extends Controller
{
    use PresentsServers;

    public function __construct(private readonly MachineChecks $checks) {}

    /**
     * Re-check: runs provision.inspect again. It never applies anything.
     */
    public function store(Request $request, Server $server, RunMachineCheck $run): RedirectResponse
    {
        $this->recheck($request, $server, $run);

        return back()->with('success', 'Checking the machine.');
    }

    /**
     * Provision: applies the plan from the latest machine check when nothing blocks (needs_attention → provisioning).
     */
    public function provision(Server $server, ApplyProvisioningPlan $apply): RedirectResponse
    {
        $this->provisionChecked($server, $apply);

        return back()->with('success', 'Provisioning started.');
    }

    /**
     * POST /api/v1/servers/{server}/provision — the Provision button: 202, or 422 with what blocks.
     */
    public function provisionApi(Server $server, ApplyProvisioningPlan $apply): JsonResponse
    {
        $commandId = $this->provisionChecked($server, $apply);

        return response()->json(['data' => ['status' => $server->refresh()->status->value, 'command_id' => $commandId ?: null]], 202);
    }

    /**
     * POST /api/v1/servers/{server}/reprovision — Re-provision / Retry provisioning: the machine check first (agents with
     * provision.v2), then the plan; a server provisioned before keeps its status when something blocks.
     */
    public function reprovisionApi(Request $request, Server $server, ProvisionServer $provision): JsonResponse
    {
        $this->authorize('update', $server);

        if ($server->status === ServerStatus::Deleting) {
            throw ValidationException::withMessages(['server' => 'The server is being deleted.']);
        }

        $commandId = $provision($server, $request->user()?->getAuthIdentifier());
        $server->refresh();

        return response()->json(['data' => [
            'status' => $server->status->value,
            'status_message' => $server->status_message,
            'stage' => $server->machineInspection()->value('command_id') === $commandId && $commandId !== '' ? 'machine_check' : 'provision',
            'command_id' => $commandId ?: null,
        ]], 202);
    }

    private function provisionChecked(Server $server, ApplyProvisioningPlan $apply): string
    {
        $this->authorize('update', $server);

        if (! in_array($server->status, [ServerStatus::NeedsAttention, ServerStatus::Error], true)) {
            throw ValidationException::withMessages(['server' => 'Only a server that needs attention or failed provisioning can be provisioned from here.']);
        }

        $check = $this->checks->current($server);

        // The latest machine check must have finished: a failed or running one says nothing about the machine now.
        if ($check === null || $server->machineInspection()->value('status') !== MachineInspection::FINISHED) {
            throw ValidationException::withMessages(['server' => 'Run the machine check first: the latest one did not finish.']);
        }

        if ($check->blocking()) {
            throw ValidationException::withMessages(['server' => $check->summary()]);
        }

        return $apply($server);
    }

    /**
     * GET /api/v1/servers/{server}/inspection — the latest report and the decisions for the current stack.
     */
    public function show(Server $server): JsonResponse
    {
        $this->authorize('view', $server);

        if (! $server->machineInspection()->exists()) {
            return response()->json(['message' => 'This server has no machine check yet.'], 404);
        }

        return response()->json(['data' => $this->machineCheck($server, $this->checks, withReport: true)]);
    }

    /**
     * POST /api/v1/servers/{server}/inspection — re-check (202; poll GET until status is no longer running).
     */
    public function storeApi(Request $request, Server $server, RunMachineCheck $run): JsonResponse
    {
        $this->recheck($request, $server, $run);

        return response()->json(['data' => $this->machineCheck($server, $this->checks)], 202);
    }

    private function recheck(Request $request, Server $server, RunMachineCheck $run): void
    {
        $this->authorize('update', $server);

        if (in_array($server->status, [ServerStatus::Deleting, ServerStatus::Creating], true)) {
            throw ValidationException::withMessages(['server' => $server->status === ServerStatus::Deleting ? 'The server is being deleted.' : 'The server agent has not connected yet.']);
        }

        if ($server->machineInspection()->value('status') === MachineInspection::RUNNING) {
            throw ValidationException::withMessages(['server' => 'A machine check is already running.']);
        }

        $run($server, MachineInspection::PURPOSE_CHECK, $request->user()?->getAuthIdentifier());
    }
}
