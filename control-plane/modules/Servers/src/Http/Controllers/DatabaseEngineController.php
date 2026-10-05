<?php

namespace Falak\Servers\Http\Controllers;

use Falak\Kernel\Http\Controller;
use Falak\Servers\Application\Actions\InstallDatabaseEngine;
use Falak\Servers\Domain\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Adding a database or cache engine (Redis, Valkey) to a provisioned server (panel Settings → Database engine and
 * `POST /api/v1/servers/{server}/database-engine`).
 */
final class DatabaseEngineController extends Controller
{
    public function store(Request $request, Server $server, InstallDatabaseEngine $install): RedirectResponse
    {
        $this->authorize('update', $server);
        $install($server, $this->engine($request), $request->user()?->getAuthIdentifier());

        return back();
    }

    public function storeApi(Request $request, Server $server, InstallDatabaseEngine $install): JsonResponse
    {
        $this->authorize('update', $server);
        $engine = $this->engine($request);
        $commandId = $install($server, $engine, $request->user()?->getAuthIdentifier());

        return response()->json(['data' => ['engine' => $engine, 'status' => 'installing', 'command_id' => $commandId]], 202);
    }

    private function engine(Request $request): string
    {
        return (string) $request->validate([
            'engine' => ['required', 'string', Rule::in([...array_keys((array) config('servers.databases', [])), ...array_keys((array) config('servers.caches', []))])],
        ])['engine'];
    }
}
