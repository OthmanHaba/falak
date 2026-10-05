<?php

namespace Falak\Providers\Http\Controllers;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Kernel\Http\Controller;
use Falak\Providers\Contracts\Data\Image;
use Falak\Providers\Contracts\Data\Region;
use Falak\Providers\Contracts\Data\Size;
use Falak\Providers\Contracts\Exceptions\ProviderException;
use Falak\Providers\Contracts\ProviderGateway;
use Falak\Providers\Domain\Models\ProviderCredential;

/**
 * JSON catalog lookups for the "create server" form (regions → sizes, images).
 */
final class CatalogController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly ProviderGateway $gateway,
    ) {}

    public function regions(string $credential): JsonResponse
    {
        return $this->respond($credential, fn (string $org) => array_map(fn (Region $r) => $r->toArray(), $this->gateway->regions($org, $credential)));
    }

    public function sizes(Request $request, string $credential): JsonResponse
    {
        $region = $request->validate(['region' => ['nullable', 'string', 'max:64']])['region'] ?? null;

        return $this->respond($credential, fn (string $org) => array_map(fn (Size $s) => $s->toArray(), $this->gateway->sizes($org, $credential, $region)));
    }

    public function images(string $credential): JsonResponse
    {
        return $this->respond($credential, fn (string $org) => array_map(fn (Image $i) => $i->toArray(), $this->gateway->images($org, $credential)));
    }

    /**
     * @param  Closure(string): list<array<string, mixed>>  $fetch
     */
    private function respond(string $credential, Closure $fetch): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $model = ProviderCredential::query()->forOrganization($organizationId)->findOrFail($credential);
        $this->authorize('view', $model);

        try {
            return response()->json(['data' => $fetch($organizationId)]);
        } catch (ProviderException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
