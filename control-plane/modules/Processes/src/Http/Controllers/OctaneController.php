<?php

namespace Falak\Processes\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Falak\Kernel\Http\Controller;
use Falak\Processes\Application\OctaneRoutes;
use Falak\Processes\Domain\Models\OctaneRoute;
use Falak\Servers\Contracts\ServerDirectory;

/**
 * Octane of a site for the Settings → Laravel section: server, port and, per server, whether the edge proxies to it.
 */
final class OctaneController extends Controller
{
    use ResolvesSite;

    public function show(Request $request, string $site, ServerDirectory $servers): JsonResponse
    {
        $site = $this->site($request, $site);
        $routes = OctaneRoute::query()->where('site_id', $site->id)->get()->keyBy('server_id');
        $enabled = OctaneRoutes::wantsOctane($site);

        $rows = [];

        foreach ($site->targets as $target) {
            $route = $routes->get($target->serverId);

            if (! $enabled && $route === null) {
                continue;
            }

            $rows[] = [
                'server_id' => $target->serverId,
                'server_name' => $servers->find($target->serverId)?->name ?? $target->serverId,
                // listening | starting | failed | draining | pending (target not ready yet)
                'status' => $route?->status->value ?? 'pending',
                'port' => $route?->port,
                'error' => $route?->error,
                'checked_at' => $route?->checked_at?->toIso8601String(),
                'listening_at' => $route?->listening_at?->toIso8601String(),
            ];
        }

        return response()->json(['data' => [
            'enabled' => $enabled,
            'server' => $site->laravel->octaneServer?->value,
            'server_label' => $site->laravel->octaneServer?->label(),
            'port' => $site->laravel->octanePort,
            'aux_port' => $site->laravel->octaneAuxPort(),
            'servers' => $rows,
        ]]);
    }
}
