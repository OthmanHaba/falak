<?php

namespace Falak\Identity\Http\Controllers\Organizations;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Falak\Identity\Application\Actions\AcceptInvitation;
use Falak\Identity\Application\Actions\InviteMember;
use Falak\Identity\Application\Actions\RevokeInvitation;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\User;
use Falak\Kernel\Http\Controller;

final class InvitationController extends Controller
{
    use ResolvesOrganization;

    public function store(Request $request, InviteMember $invite): RedirectResponse
    {
        $organization = $this->organization();
        $this->authorize('manageMembers', $organization);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::enum(Role::class)->only(Role::assignable())],
        ]);

        /** @var User $inviter */
        $inviter = $request->user();
        $invite($organization, $inviter, $data['email'], Role::from($data['role']));

        return back();
    }

    public function destroy(string $invitation, RevokeInvitation $revoke): RedirectResponse
    {
        $organization = $this->organization();
        $this->authorize('manageMembers', $organization);

        $revoke($organization->invitations()->findOrFail($invitation));

        return back();
    }

    public function show(Request $request, string $token): Response
    {
        $invitation = AcceptInvitation::findPending($token);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Identity/invitations/show', [
            'token' => $token,
            'invitation' => $invitation ? [
                'organization' => $invitation->organization?->name,
                'role' => $invitation->role->label(),
                'invited_by' => $invitation->inviter?->name,
                'email_matches' => mb_strtolower($invitation->email) === mb_strtolower($user->email),
                'email' => $invitation->email,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ] : null,
        ]);
    }

    public function accept(Request $request, string $token, AcceptInvitation $accept): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $accept($user, $token);

        return redirect(config('fortify.home'));
    }
}
