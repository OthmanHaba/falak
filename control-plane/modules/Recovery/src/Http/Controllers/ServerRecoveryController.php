<?php

namespace Falak\Recovery\Http\Controllers;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Recovery\Application\ServerRecoveryPlanner;
use Falak\Recovery\Application\ServerRecoveryRunner;
use Falak\Recovery\Domain\Models\ServerRecovery;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "This server is gone" (admins: recovery.servers): pick a replacement, see the dry run (what comes back from where,
 * the data loss per database), confirm with the lost server's name, follow the steps, retry a failed one.
 */
final class ServerRecoveryController extends Controller
{
    public const PERMISSION = 'recovery.servers';

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly ServerDirectory $servers,
    ) {}

    public function show(Request $request, string $server): Response
    {
        $lost = $this->lost($request, $server);
        $recovery = ServerRecovery::query()->where('lost_server_id', $lost->id)->latest()->first();

        return Inertia::render('Recovery/ServerRecovery', [
            'server' => ['id' => $lost->id, 'name' => $lost->name, 'ipv4' => $lost->ipv4, 'status' => $lost->status->value],
            'candidates' => collect($this->servers->forOrganization($lost->organizationId))
                ->reject(fn (ServerData $candidate) => $candidate->id === $lost->id)
                ->map(fn (ServerData $candidate) => ['id' => $candidate->id, 'name' => $candidate->name, 'status' => $candidate->status->value, 'status_label' => $candidate->status->label(), 'ipv4' => $candidate->ipv4, 'docker' => $candidate->docker])
                ->values(),
            'recovery' => $recovery !== null ? self::resource($recovery) : null,
        ]);
    }

    /** POST /servers/{server}/recovery/plan {target_server_id?}: the dry run, nothing changes. */
    public function plan(Request $request, string $server, ServerRecoveryPlanner $planner): JsonResponse
    {
        $lost = $this->lost($request, $server);
        $data = $request->validate(['target_server_id' => ['nullable', 'string', 'max:26']]);

        return response()->json(['data' => $planner->plan($lost, $data['target_server_id'] ?? null)]);
    }

    /** POST /servers/{server}/recovery {target_server_id, confirm}: start; confirm is the lost server's name. */
    public function store(Request $request, string $server, ServerRecoveryPlanner $planner, ServerRecoveryRunner $runner): RedirectResponse
    {
        $lost = $this->lost($request, $server);
        $data = $request->validate([
            'target_server_id' => ['required', 'string', 'max:26'],
            'confirm' => ['required', 'string'],
        ]);

        if ($data['confirm'] !== $lost->name) {
            throw ValidationException::withMessages(['confirm' => "Type {$lost->name} to confirm the server is gone."]);
        }

        $plan = $planner->plan($lost, $data['target_server_id']);
        $target = $this->servers->find(strtolower($data['target_server_id']));
        abort_if($target === null, 404);

        $runner->start($lost, $target, $plan, (string) $request->user()?->getAuthIdentifier());

        return redirect("/servers/{$lost->id}/recovery");
    }

    /** GET /recoveries/{recovery}: progress (the page polls it). */
    public function progress(Request $request, string $recovery): JsonResponse
    {
        return response()->json(['data' => self::resource($this->recovery($request, $recovery))]);
    }

    /** POST /recoveries/{recovery}/steps/{step}/retry */
    public function retry(Request $request, string $recovery, string $step, ServerRecoveryRunner $runner): JsonResponse
    {
        $model = $this->recovery($request, $recovery);
        abort_unless(array_key_exists($step, ServerRecovery::STEPS), 404);
        $runner->retry($model, $step);

        return response()->json(['data' => self::resource($model->refresh())]);
    }

    private function lost(Request $request, string $server): ServerData
    {
        $organizationId = $this->organization->requireId();
        $lost = $this->servers->find(strtolower($server));

        // Other organizations' servers don't exist.
        abort_if($lost === null || $lost->organizationId !== $organizationId, 404);
        $this->access->authorize($request->user(), $organizationId, self::PERMISSION);

        return $lost;
    }

    private function recovery(Request $request, string $id): ServerRecovery
    {
        $organizationId = $this->organization->requireId();
        $recovery = ServerRecovery::query()->where('organization_id', $organizationId)->find(strtolower($id));
        abort_if($recovery === null, 404);
        $this->access->authorize($request->user(), $organizationId, self::PERMISSION);

        return $recovery;
    }

    /** @return array<string, mixed> */
    private static function resource(ServerRecovery $recovery): array
    {
        return [
            'id' => $recovery->id,
            'lost_server' => ['id' => $recovery->lost_server_id, 'name' => $recovery->lost_server_name],
            'target_server' => ['id' => $recovery->target_server_id, 'name' => $recovery->target_server_name],
            'status' => $recovery->status,
            'current_step' => $recovery->current_step,
            'steps' => array_map(fn (array $step) => [
                ...$step,
                'items' => array_map(fn (array $item) => array_intersect_key($item, array_flip(['id', 'label', 'state', 'message', 'phase'])), $step['items']),
            ], $recovery->steps),
            'plan' => $recovery->plan,
            'created_at' => $recovery->created_at->toIso8601String(),
            'finished_at' => $recovery->finished_at?->toIso8601String(),
        ];
    }
}
