<?php

namespace Falak\Servers\Http\Controllers;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Servers\Application\Actions\AttachSshKey;
use Falak\Servers\Application\Actions\CreateSshKey;
use Falak\Servers\Application\Actions\DeleteSshKey;
use Falak\Servers\Application\Actions\DetachSshKey;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Domain\Models\SshKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SshKeyController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
    ) {}

    public function index(Request $request): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'servers.view');

        return Inertia::render('Servers/SshKeys', [
            'keys' => SshKey::query()->where('organization_id', $organizationId)->withCount('servers')->orderBy('name')->get()->map(fn (SshKey $key) => [
                'id' => $key->id,
                'name' => $key->name,
                'fingerprint' => $key->fingerprint,
                'type' => explode(' ', $key->public_key)[0],
                'servers_count' => $key->getAttribute('servers_count'),
                'created_at' => $key->created_at?->toIso8601String(),
            ]),
            'canManage' => $this->access->can($request->user(), $organizationId, 'ssh_keys.manage'),
        ]);
    }

    public function store(Request $request, CreateSshKey $create): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'ssh_keys.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'public_key' => ['required', 'string', 'max:16384'],
        ]);

        $create($organizationId, $request->user()?->getAuthIdentifier(), $data['name'], $data['public_key']);

        return back();
    }

    public function destroy(SshKey $sshKey, DeleteSshKey $delete): RedirectResponse
    {
        $this->authorize('delete', $sshKey);
        $delete($sshKey);

        return back();
    }

    public function attach(Request $request, Server $server, AttachSshKey $attach): RedirectResponse
    {
        $this->authorize('update', $server);

        $data = $request->validate([
            'ssh_key_id' => ['required', 'string'],
            'unix_user' => ['required', 'string'],
        ]);

        $key = SshKey::query()->where('organization_id', $server->organization_id)->findOrFail($data['ssh_key_id']);
        $attach($server, $key, $data['unix_user']);

        return back();
    }

    public function detach(Request $request, Server $server, string $sshKey, DetachSshKey $detach): RedirectResponse
    {
        $this->authorize('update', $server);

        $key = $server->sshKeys()->findOrFail($sshKey);
        $detach($server, $key, $request->string('unix_user')->toString() ?: null);

        return back();
    }
}
