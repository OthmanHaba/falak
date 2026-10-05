<?php

namespace Falak\Identity\Http\Controllers\Api;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Identity\Domain\Models\PersonalAccessToken;
use Falak\Identity\Domain\Models\User;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeController extends Controller
{
    public function __invoke(Request $request, CurrentOrganization $current, OrganizationAccess $access): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $organization = $current->require();
        $token = $user->currentAccessToken();

        return response()->json([
            'data' => [
                'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
                'organization' => [...$organization->toArray(), 'role' => $access->roleOf($user->id, $organization->id)?->value],
                'token' => $token instanceof PersonalAccessToken ? ['name' => $token->name, 'abilities' => $token->abilities] : null,
            ],
        ]);
    }
}
