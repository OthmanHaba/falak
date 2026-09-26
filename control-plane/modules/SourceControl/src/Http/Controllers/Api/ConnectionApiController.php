<?php

namespace Kiln\SourceControl\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\SourceControl\Application\Actions\CreateConnection;
use Kiln\SourceControl\Domain\Models\Connection;
use Kiln\SourceControl\Http\Requests\StoreConnectionRequest;

/**
 * Public API v1: source control connections (credentials are write-only and never returned).
 */
final class ConnectionApiController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'source_control.view');

        return response()->json(['data' => Connection::query()
            ->where('organization_id', $organizationId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (Connection $connection) => $this->resource($connection))
            ->all()]);
    }

    public function store(StoreConnectionRequest $request, CreateConnection $create): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'source_control.manage');

        $connection = $create(
            $organizationId,
            $request->user()?->getAuthIdentifier(),
            $request->provider(),
            $request->validated('auth_type'),
            $request->credentials(),
            $request->validated('name'),
            $request->validated('base_url'),
        );

        return response()->json(['data' => $this->resource($connection)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(Connection $connection): array
    {
        return [
            'id' => $connection->id,
            'provider' => $connection->provider->value,
            'name' => $connection->name,
            'auth_type' => $connection->auth_type,
            'base_url' => $connection->base_url,
            'created_at' => $connection->created_at?->toIso8601String(),
        ];
    }
}
