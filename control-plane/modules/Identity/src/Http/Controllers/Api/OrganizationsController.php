<?php

namespace Falak\Identity\Http\Controllers\Api;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Identity\Domain\Models\Organization;
use Falak\Identity\Domain\Models\PersonalAccessToken;
use Falak\Identity\Domain\Models\User;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/organizations — API tokens are pinned to one organization, so a token only sees that
 * one; session-authenticated requests list every membership.
 */
final class OrganizationsController extends Controller
{
    public function __invoke(Request $request, CurrentOrganization $current, OrganizationAccess $access): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();

        $organizations = $token instanceof PersonalAccessToken
            ? Organization::query()->whereKey($token->organization_id)->get()
            : $user->organizations()->orderBy('name')->get();

        return response()->json(['data' => $organizations->map(fn (Organization $o) => [
            'id' => $o->id,
            'name' => $o->name,
            'slug' => $o->slug,
            'role' => $access->roleOf($user->id, $o->id)?->value,
            'current' => $o->id === $current->id(),
        ])->values()]);
    }
}
