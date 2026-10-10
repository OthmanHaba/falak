<?php

namespace Falak\Limits\Http\Controllers;

use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Limits\Application\CapacityReport;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CapacityController extends Controller
{
    /**
     * GET /servers/{server}/capacity (and /api/v1/…): every service's limits on the server against its RAM and cores.
     */
    public function show(Request $request, string $server, ServerDirectory $servers, OrganizationAccess $access, CapacityReport $report): JsonResponse
    {
        $data = $servers->find($server);

        // Other organizations' servers don't exist.
        abort_if($data === null || ! $access->can($request->user(), $data->organizationId, 'servers.view'), 404);

        return response()->json(['data' => $report->for($data)]);
    }
}
