<?php

namespace Kiln\Identity\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Identity\Domain\Models\Organization;
use Kiln\Identity\Domain\Models\PersonalAccessToken;
use Kiln\Identity\Domain\Models\User;
use Kiln\Kernel\Http\Controller;

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
