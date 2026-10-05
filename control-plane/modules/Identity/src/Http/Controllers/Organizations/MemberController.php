<?php

namespace Falak\Identity\Http\Controllers\Organizations;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Falak\Identity\Application\Actions\ChangeMemberRole;
use Falak\Identity\Application\Actions\RemoveMember;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\Invitation;
use Falak\Identity\Domain\Models\User;
use Falak\Kernel\Http\Controller;

final class MemberController extends Controller
{
    use ResolvesOrganization;

    public function index(Request $request, OrganizationAccess $access): Response
    {
        $organization = $this->organization();
        $this->authorize('viewMembers', $organization);

        /** @var User $user */
        $user = $request->user();

        $members = $organization->members()->orderBy('name')->get()->map(fn (User $member) => [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'role' => $access->roleOf($member->id, $organization->id)?->value,
            'two_factor' => $member->hasTwoFactorEnabled(),
            'joined_at' => $member->pivot?->created_at?->toIso8601String(),
        ]);

        $canManage = $user->can('manageMembers', $organization);

        return Inertia::render('Identity/organizations/members', [
            'members' => $members,
            'invitations' => $canManage
                ? $organization->invitations()->pending()->with('inviter:id,name')->latest()->get()->map(fn (Invitation $invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'invited_by' => $invitation->inviter?->name,
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                ])
                : [],
            'roles' => collect(Role::cases())->map(fn (Role $role) => [
                'value' => $role->value,
                'label' => $role->label(),
                'description' => $role->description(),
                'assignable' => in_array($role, Role::assignable(), true),
            ]),
            'myRole' => $access->roleOf($user->id, $organization->id)?->value,
            'canManage' => $canManage,
        ]);
    }

    public function update(Request $request, string $member, ChangeMemberRole $change): RedirectResponse
    {
        $organization = $this->organization();
        $this->authorize('manageMembers', $organization);

        $data = $request->validate(['role' => ['required', Rule::enum(Role::class)]]);

        /** @var User $actor */
        $actor = $request->user();
        $change($organization, $actor, $organization->members()->findOrFail($member), Role::from($data['role']));

        return back();
    }

    public function destroy(Request $request, string $member, RemoveMember $remove): RedirectResponse
    {
        $organization = $this->organization();

        /** @var User $actor */
        $actor = $request->user();

        if ($actor->id !== $member) {
            $this->authorize('manageMembers', $organization);
        }

        $remove($organization, $actor, $organization->members()->findOrFail($member));

        return $actor->id === $member ? redirect(config('fortify.home')) : back();
    }
}
