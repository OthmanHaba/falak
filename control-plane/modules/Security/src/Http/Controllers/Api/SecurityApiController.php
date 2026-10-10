<?php

namespace Falak\Security\Http\Controllers\Api;

use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Security\Application\Actions\ApplyFixes;
use Falak\Security\Application\Actions\StartAudit;
use Falak\Security\Domain\Enums\AuditStatus;
use Falak\Security\Domain\Models\Audit;
use Falak\Security\Domain\Models\Finding;
use Falak\Security\Domain\Models\FixRun;
use Falak\Security\Http\Controllers\SecurityController;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * /api/v1/servers/{server}/security: the latest baseline report, running an audit and applying a fix.
 */
final class SecurityApiController extends Controller
{
    public function __construct(
        private readonly OrganizationAccess $access,
        private readonly ServerDirectory $servers,
    ) {}

    public function show(Request $request, string $server): JsonResponse
    {
        $data = $this->server($request->user(), $server);
        $audit = Audit::query()->where('server_id', $data->id)->where('status', AuditStatus::Completed)->latest('ran_at')->latest('id')->first();

        if ($audit === null) {
            return response()->json(['message' => 'This server has not been audited yet.'], 404);
        }

        return response()->json(['data' => [
            ...SecurityController::presentAudit($audit),
            'findings' => $audit->findings()->get()->map(fn (Finding $f) => [
                'id' => $f->check_id, 'title' => $f->title, 'area' => $f->area, 'status' => $f->status, 'severity' => $f->severity,
                'evidence' => $f->evidence, 'fix_id' => $f->fix_id,
            ])->values(),
        ]]);
    }

    public function audit(Request $request, string $server, StartAudit $start, AuditLog $log): JsonResponse
    {
        $data = $this->server($request->user(), $server, 'security.fix');

        if (! $data->isActive()) {
            throw ValidationException::withMessages(['server' => 'The server is audited once it is active.']);
        }

        $audit = $start($data, 'manual', (string) $request->user()?->getAuthIdentifier());
        $log->record('security.audit_requested', 'server', $data->id, ['audit_id' => $audit?->id], $data->organizationId);

        return response()->json(['data' => ['id' => $audit?->id, 'status' => $audit?->status->value, 'error' => $audit?->error]], 202);
    }

    public function fix(Request $request, string $server, ApplyFixes $fixes): JsonResponse
    {
        $data = $this->server($request->user(), $server, 'security.fix');
        $validated = $request->validate([
            'fix_id' => ['required', 'string', 'max:120'],
            'confirm' => ['sometimes', 'boolean'],
            'reboot_at' => ['nullable', 'string', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/'],
        ]);

        $run = $fixes->one($data, $validated['fix_id'], (string) $request->user()?->getAuthIdentifier(), (bool) ($validated['confirm'] ?? false), $validated['reboot_at'] ?? null);

        return response()->json(['data' => self::presentRun($run)], 202);
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentRun(FixRun $run): array
    {
        return ['id' => $run->id, 'fix_id' => $run->fix_id, 'status' => $run->status->value, 'error' => $run->error];
    }

    private function server(?Authenticatable $user, string $serverId, string $permission = 'security.view'): ServerData
    {
        $server = $this->servers->find(strtolower($serverId));

        abort_if($server === null || ! $this->access->can($user, $server->organizationId, 'security.view'), 404, 'Server not found.');
        $this->access->authorize($user, $server->organizationId, $permission);

        return $server;
    }
}
