<?php

namespace Kiln\Identity\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Identity\Domain\Models\PersonalAccessToken;
use Kiln\Identity\Domain\Models\User;
use Kiln\Kernel\Http\Controller;

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
