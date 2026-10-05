<?php

namespace Falak\Fleet\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Domain\Models\Command;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;

/**
 * UI endpoint: status + output of one command (initial load and polling fallback for live logs).
 */
final class CommandController extends Controller
{
    public function show(Request $request, string $command, AgentGateway $gateway, OrganizationAccess $access): JsonResponse
    {
        $model = Command::query()->findOrFail($command);

        abort_if($model->hasPrivateOutput(), 404);
        abort_unless($access->can($request->user(), $model->organization_id, 'fleet.commands.view'), 404);

        $after = (int) $request->query('after', '-1');
        $output = $gateway->output($model->id, $after);

        return response()->json([
            'data' => [
                'id' => $model->id,
                'server_id' => $model->server_id,
                'type' => $model->type,
                'status' => $model->status->value,
                'exit_code' => $model->exit_code,
                'error' => $model->error,
                'queued_at' => $model->queued_at->toIso8601String(),
                'started_at' => $model->started_at?->toIso8601String(),
                'finished_at' => $model->finished_at?->toIso8601String(),
                'lines' => $output->lines,
                'last_seq' => $output->lastSeq,
            ],
        ]);
    }
}
