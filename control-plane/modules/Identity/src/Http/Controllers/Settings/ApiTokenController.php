<?php

namespace Falak\Identity\Http\Controllers\Settings;

use Falak\Identity\Application\Actions\CreateApiToken;
use Falak\Identity\Application\Actions\RevokeApiToken;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Domain\Models\PersonalAccessToken;
use Falak\Identity\Domain\Models\User;
use Falak\Identity\Infrastructure\SpatieOrganizationAccess;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

final class ApiTokenController extends Controller
{
    public function index(Request $request, CurrentOrganization $organization, SpatieOrganizationAccess $access, PermissionRegistry $registry): Response
    {
        /** @var User $user */
        $user = $request->user();
        $organizationId = $organization->requireId();
        $held = $access->permissionsOf($user, $organizationId);

        return Inertia::render('Identity/settings/api-tokens', [
            'tokens' => $user->tokens()
                ->where('organization_id', $organizationId)
                ->latest()
                ->get()
                ->map(fn (PersonalAccessToken $token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'abilities' => $token->abilities,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'expires_at' => $token->expires_at?->toIso8601String(),
                    'created_at' => $token->created_at?->toIso8601String(),
                ]),
            'abilities' => collect($registry->all())
                ->filter(fn ($permission) => in_array($permission->name, $held, true))
                ->map(fn ($permission) => ['name' => $permission->name, 'description' => $permission->description, 'group' => $permission->group])
                ->values(),
            'plainTextToken' => $request->session()->get('plainTextToken'),
        ]);
    }

    public function store(Request $request, CurrentOrganization $organization, CreateApiToken $create): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'max:100'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $token = $create(
            $user,
            $organization->requireId(),
            $data['name'],
            array_values($data['abilities']),
            isset($data['expires_in_days']) ? Carbon::now()->addDays((int) $data['expires_in_days']) : null,
        );

        return back()->with('plainTextToken', $token->plainTextToken);
    }

    public function destroy(Request $request, int $token, RevokeApiToken $revoke): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $revoke($user, $token);

        return back();
    }
}
